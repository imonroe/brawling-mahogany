<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Person;
use App\Support\Deals\DealHeader;
use App\Support\Documents\DocumentStorage;
use App\Support\Documents\RefusedDocument;
use App\Support\Documents\UnsupportedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A deal's documents — S21, the eighth deal tab (issues #98, #99, #100).
 *
 * Slice 2 accepted uploads in exactly one place: a property's photo gallery,
 * images only, because #63 closed its residual window by **restricting the
 * context** rather than inspecting content. This is the general path that
 * restriction was standing in for, and it exists only because
 * {@see \App\Support\Documents\SensitiveContent} does the inspecting now.
 *
 * ## The refusal is a response, not an error
 *
 * A file this product will not keep comes back through
 * {@see RefusedDocument}, and the screen gets **three** things: what was
 * refused, why, and where to put it instead. S53 lists the third as a key
 * state and #99 says why — *"that is the part that makes this acceptable
 * rather than infuriating."* A validation error carrying one sentence would
 * lose the other two, so the refusal rides in its own prop and opens a dialog.
 *
 * `UnsupportedDocument` is deliberately handled differently, as a field error:
 * a `.pages` file is a mistake somebody fixes by exporting again, not a policy
 * they need explained.
 */
class DealDocumentController extends Controller
{
    public function index(Deal $deal): Response
    {
        $this->authorize('view', $deal);

        $documents = Document::query()
            ->where('documentable_type', $deal->getMorphClass())
            ->where('documentable_id', $deal->getKey())
            ->with('uploader')
            ->latest('created_at')
            /*
             * `created_at` is `timestamp(0)`, so a bulk upload shares a second
             * and Postgres is free to return heap order within it. The same
             * defect S47's queue lists paid for; the tiebreaker is not
             * decoration.
             */
            ->latest('id')
            ->get();

        return Inertia::render('Deals/Documents', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => '/deals/'.$deal->getKey(),
            'documents' => $documents->map(self::row(...))->values()->all(),
            'categories' => DocumentCategory::options(),
            'visibilities' => DocumentVisibility::options(),
            'maxBytes' => DocumentStorage::MAX_BYTES,
            /*
             * S53's refusal, read back off the session after `store()`
             * redirected. Not `Inertia::flash()`, which fires a router event
             * suited to a toast that disappears — a refusal has three things
             * to say and a place to send somebody, and it has to stay on
             * screen until they have read it.
             */
            'refusal' => session('refusal'),
            'can' => [
                'upload' => $deal->exists && request()->user()?->can('update', $deal),
            ],
        ]);
    }

    public function store(Request $request, Deal $deal, DocumentStorage $storage): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate([
            'document' => ['required', 'file', 'max:'.(int) (DocumentStorage::MAX_BYTES / 1024)],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'visibility' => ['required', Rule::enum(DocumentVisibility::class)],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Person $person */
        $person = $request->user();

        try {
            $document = $storage->store(
                $deal,
                $request->file('document'),
                $person,
                DocumentCategory::from($validated['category']),
                DocumentVisibility::from($validated['visibility']),
            );
        } catch (RefusedDocument $refusal) {
            /*
             * Not `withErrors`. A refusal needs the category, the reason and
             * the alternative together — see the class docblock — and it is
             * flashed rather than thrown so the screen can open S53's dialog
             * on the next render.
             *
             * Nothing here names the file. `RefusedDocument` never carries the
             * matched content, and a filename is often the most descriptive
             * thing about a document somebody just tried to upload.
             */
            return back()->with('refusal', [
                'category' => $refusal->category->label(),
                'reason' => $refusal->getMessage(),
                'alternative' => $refusal->alternative(),
            ]);
        } catch (UnsupportedDocument $refusal) {
            return back()->withErrors(['document' => $refusal->getMessage()]);
        }

        if ($validated['caption'] !== null && $validated['caption'] !== '') {
            $document->forceFill(['caption' => $validated['caption']])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document added.')]);

        return back(fallback: route('deals.documents.index', $deal));
    }

    /**
     * `{document}` is resolved **through** `{deal}` by `scopeBindings()`, so a
     * document on another deal is a 404 before this method runs — the same
     * mechanism `{offer}` uses two blocks up in `routes/web.php`.
     *
     * A belt-and-braces `abort_unless` on the parent ids was written here and
     * then removed: mutating it away changed no test, because there is no
     * request that reaches it. An unreachable guard reads like a rule somebody
     * is enforcing, which is worse than not having one — the route group is
     * where this is actually enforced, and moving these lines out of it is the
     * change that would break it.
     */
    public function destroy(Deal $deal, Document $document, DocumentStorage $storage): RedirectResponse
    {
        $this->authorize('delete', $document);

        $storage->remove($document);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document removed.')]);

        return back(fallback: route('deals.documents.index', $deal));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'name' => $document->original_name,
            'caption' => $document->caption,
            'category' => $document->category->value,
            'categoryLabel' => $document->category->label(),
            'visibility' => $document->visibility->value,
            'sizeBytes' => $document->size_bytes,
            'uploadedAt' => $document->created_at?->toIso8601String(),
            'uploadedBy' => $document->uploader?->displayNameWithin($document->team),
            /*
             * Whether the bytes were readable, never a verdict of safety.
             * `not_scanned` is an image or a text-free PDF — see
             * `ReadableText::from()`, which returns null rather than '' for
             * exactly this reason. A screen that drew "clean" over a
             * photograph of a cheque would be believed.
             */
            'scanState' => $document->scan_state,
        ];
    }
}
