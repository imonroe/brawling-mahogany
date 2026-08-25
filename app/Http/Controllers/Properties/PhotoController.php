<?php

declare(strict_types=1);

namespace App\Http\Controllers\Properties;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Person;
use App\Models\Property;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentStorage;
use App\Support\Documents\PhotoGallery;
use App\Support\Documents\UnsupportedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * S38 — a property's photographs (PRD §4.6 F6.4–F6.6, §7.14 · issue #63).
 *
 * ## Every download is authorized, and audited
 *
 * F6.4: *"no public buckets, every download authorized and short-lived."*
 * PRD §9 adds that document access is an audited event. Both are satisfied by
 * there being exactly one way to read a file — `download()` below, which asks
 * the policy, writes the entry, and streams the bytes. A presigned
 * object-store URL would be a second way, and the one that cannot be audited:
 * an entry written when a link is *minted* records an intention rather than a
 * read.
 *
 * ## Images only, against a property only
 *
 * #63's residual window, closed by its first option and recorded there: this
 * is the only upload path in the product, it hangs off a `Property` and never
 * a deal, and it takes photographs. PRD §14.3 names uploaded financial
 * instruments as the largest liability here, and a photographed cheque is an
 * image — so the F6.6 warning on the screen says so in those words.
 */
class PhotoController extends Controller
{
    public function store(
        Request $request,
        Property $property,
        DocumentStorage $storage,
    ): RedirectResponse {
        $this->authorize('update', $property);

        $request->validate([
            'photo' => ['required', 'file', 'max:'.(DocumentStorage::MAX_BYTES / 1024)],
        ]);

        /** @var Person $person */
        $person = $request->user();

        try {
            $storage->store($property, $request->file('photo'), $person);
        } catch (UnsupportedDocument $refusal) {
            /*
             * A refusal a person can act on, rather than a 500. The message
             * never names the file: PRD §9 keeps PII out of logs, and a
             * filename is often the most descriptive thing about a document.
             */
            return back()->withErrors(['photo' => $refusal->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo added.')]);

        return back(fallback: route('properties.show', $property));
    }

    public function reorder(Request $request, Property $property, PhotoGallery $gallery): RedirectResponse
    {
        $this->authorize('update', $property);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['ids']);

        $gallery->reorder($property, $ids);

        return back(fallback: route('properties.show', $property));
    }

    public function setPrimary(Property $property, Document $photo, PhotoGallery $gallery): RedirectResponse
    {
        $this->authorize('update', $property);
        $this->authorize('update', $photo);

        $gallery->setPrimary($property, $photo);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Primary photo set.')]);

        return back(fallback: route('properties.show', $property));
    }

    public function destroy(
        Property $property,
        Document $photo,
        DocumentStorage $storage,
        PhotoGallery $gallery,
    ): RedirectResponse {
        $this->authorize('update', $property);
        $this->authorize('delete', $photo);

        $storage->remove($photo);
        $gallery->afterRemoval($property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return back(fallback: route('properties.show', $property));
    }

    /**
     * The only way to read a file, and it says so in the audit log.
     */
    public function download(
        Request $request,
        Property $property,
        Document $photo,
        DocumentStorage $storage,
        AuditLogger $audit,
    ): Response {
        $this->authorize('view', $property);
        $this->authorize('view', $photo);

        abort_unless($storage->exists($photo), 404);

        /** @var Person $person */
        $person = $request->user();

        /*
         * PRD §9: document access is an audited event. Written **before** the
         * bytes are handed over, so a read that failed halfway is still a read
         * that happened — the entry answers "who saw this", and a stream that
         * broke does not un-see it.
         *
         * The filename is not in the entry. `auditable` is the row, and the
         * row holds the name for anybody who is entitled to look it up.
         */
        $audit->record(
            action: 'document.accessed',
            auditable: $photo,
            teamId: $photo->team_id,
            actorPersonId: $person->getKey(),
            after: ['documentable_type' => $photo->documentable_type, 'documentable_id' => $photo->documentable_id],
        );

        return response($storage->contents($photo), 200, [
            'Content-Type' => $photo->mime_type,
            /*
             * `inline` for an image the gallery renders; the name is the one
             * the person uploaded, which is theirs to see.
             *
             * Built by `HeaderUtils` rather than by hand: `addslashes()` on a
             * non-ASCII original filename produces a header value that is not
             * latin-1, which some clients reject and others mangle. The RFC
             * 5987 encoding is what that helper exists for.
             */
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $photo->original_name,
                // The fallback for a client that cannot read the encoded form.
                'photo.'.pathinfo($photo->path, PATHINFO_EXTENSION),
            ),
            /*
             * The complement to deriving `mime_type` from the bytes: the type
             * we send is true of the file, and this stops a browser looking
             * for a second opinion. Together they make `inline` safe by
             * construction rather than by the type happening to be honest.
             */
            'X-Content-Type-Options' => 'nosniff',
            // Private and short-lived, per F6.4. Never a shared cache.
            'Cache-Control' => 'private, max-age=60, no-store',
        ]);
    }
}
