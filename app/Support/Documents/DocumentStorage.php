<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Logging\Redactor;
use App\Models\Document;
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only thing that writes files, and the only thing that mints a key
 * (PRD §4.6 F6.4 · S38 · issue #63).
 *
 * ## The key reveals nothing
 *
 * F6.4 asks that a leaked key say nothing — not
 * `team-3/123-main-st/sellers-bank-statement.pdf`. So a key is
 * `{team}/{ulid}.{ext}` and nothing else: no address, no person, no category,
 * no original filename. The team segment is there so a bucket can be reasoned
 * about and lifecycle-ruled per tenant, and a ULID is not guessable from
 * another one.
 *
 * The extension is kept, and only from an allowlist. It is what lets a
 * download set a truthful `Content-Type` without trusting the browser's
 * claim, and an extension outside the list means a file this slice does not
 * accept at all.
 *
 * ## Images only, against a property only
 *
 * #63 names a **residual window**: between this issue and #100 the product
 * accepts files with a warning and no content scan, and PRD §14.3 calls
 * uploaded financial instruments the single largest liability in the product
 * — *"a photographed check is an image, exactly what a photo gallery
 * accepts."* Of the two closures #63 offers, this takes the first and records
 * it: **restrict by context.** The gallery accepts images only, against a
 * `Property` only, never a deal, and the warning names cheques explicitly.
 * That makes the misuse unlikely rather than impossible, which is what a
 * time-boxed trade looks like when it is written down instead of discovered.
 *
 * `DocumentCategory` carries all seven of PRD §6.3's categories, because it is
 * the **vocabulary** and a test holds it to the PRD. Nothing in this slice can
 * set any but `Photo`: the default below is the only value written, and there
 * is no screen that offers a choice. #63's *"do not ship a general-purpose
 * upload UI"* is about the path, not the enum — and `RestrictedDocumentCategory`
 * already exists for F6.5's refusal list, deliberately as a separate type so
 * "Bank statement" can never end up in a dropdown.
 */
final class DocumentStorage
{
    /** The disk everything customer-uploaded lives on. Never public. */
    public const DISK = 'documents';

    /** 15MB. A phone photograph is 2–5; a scanned contract is not this slice's problem. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * What a photo may be, **by detected type**, and the extension each gets.
     *
     * An allowlist rather than a denylist, for the reason `SafeUrl` is one: a
     * denylist is a list of the attacks somebody thought of.
     *
     * ## Keyed by mime, because several report as one extension
     *
     * This was a one-to-one `extension => mime` map searched backwards, and
     * that shape is what broke HEIC. `finfo` derives a HEIF file's type from
     * its **major brand**, and encoders — Apple's included — write `mif1` at
     * least as often as `heic`:
     *
     * | brand  | detected                |
     * |--------|-------------------------|
     * | `heic` | `image/heic`            |
     * | `mif1` | `image/heif`            |
     * | `msf1` | `image/heif-sequence`   |
     *
     * So moving the check from the filename to the bytes — which is right, and
     * makes the stored `mime_type` true — rejected an iPhone photograph with a
     * message naming HEIC as accepted. The most likely file in the world to
     * reach a property gallery, refusing itself.
     *
     * `image/heif-sequence` is deliberately **absent**: `msf1` is a Live
     * Photo, which is a video, and this product does not transcode. Refusing
     * it with a sentence beats storing a moving image under `.heic` and
     * calling it a photograph.
     *
     * @var array<string, string>
     */
    public const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
    ];

    /**
     * What a **document** may be, beyond the gallery's images.
     *
     * A working document is a report, a disclosure, a receipt, a letter — so
     * PDF, plain text, and the two office formats a team actually exchanges.
     * Keyed by detected type for the same reason {@see self::IMAGE_TYPES} is:
     * an allowlist checked against the browser's claim is a denylist with
     * extra steps.
     *
     * **Deliberately no `.zip`, and no `.docm`.** An archive hides its
     * contents from the scan by construction — F6.7 would be looking at a
     * container — and a macro-enabled document is an executable a team member
     * would open. Both are refusals somebody can work around by exporting
     * differently, which is the right kind of refusal to make.
     *
     * @var array<string, string>
     */
    public const DOCUMENT_TYPES = [
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    /**
     * Store one file against a subject, and record it.
     *
     * @throws UnsupportedDocument when the file is not something this slice accepts
     * @throws RefusedDocument when the scan finds something the product will not keep
     */
    public function store(
        Model $subject,
        UploadedFile $file,
        Person $actor,
        DocumentCategory $category = DocumentCategory::Photo,
        DocumentVisibility $visibility = DocumentVisibility::Internal,
    ): Document {
        /*
         * **The bytes decide, not the filename.**
         *
         * `getClientOriginalExtension()` is a string the browser sent, so it
         * answered *"is this an image"* by asking the uploader — an allowlist
         * checked against a claim is the shape of a denylist with extra steps.
         * `getMimeType()` runs `finfo` over the file's actual contents.
         *
         * What that buys, given the disk is private and every download is
         * streamed with a type this class chose: the stored `mime_type` is now
         * true of the file. A `.jpg` whose contents are something else no
         * longer becomes a row that says `image/jpeg` and a file that is not —
         * which is the row a later slice's thumbnailer, virus scan or preview
         * would trust.
         */
        $detected = mb_strtolower((string) $file->getMimeType());

        /*
         * A photograph goes in the gallery and everything else is a document,
         * and the allowlist follows the category rather than the caller. A
         * `photo` that is a PDF is a mistake somebody will make and should be
         * told about; a `disclosure` that is a JPEG is a photographed
         * document, which is ordinary and allowed.
         */
        $accepted = $category === DocumentCategory::Photo
            ? self::IMAGE_TYPES
            : self::IMAGE_TYPES + self::DOCUMENT_TYPES;

        if (! array_key_exists($detected, $accepted)) {
            throw $category === DocumentCategory::Photo
                ? UnsupportedDocument::extension(mb_strtolower($file->getClientOriginalExtension()) ?: $detected)
                : UnsupportedDocument::documentType();
        }

        $extension = $accepted[$detected];

        if ($file->getSize() > self::MAX_BYTES) {
            throw UnsupportedDocument::tooLarge(self::MAX_BYTES);
        }

        /*
         * **The scan, here, before the transaction and before any write.**
         *
         * PRD §4.6's four requirements in order: scan on receipt, refuse and
         * discard before anything reaches permanent storage, log without
         * retaining, never hand a refused file to a third party. The position
         * of this call is the second one — everything below it writes, and
         * nothing above it does.
         *
         * The honest limit, because §14.3 says not to claim more than §8.4
         * delivers: PHP wrote this upload to its own temporary directory
         * before any of this code ran, so *"in memory"* means the bytes never
         * reach the **permanent** store, not that they never touched a disk.
         * The temporary copy is unlinked when the request ends.
         */
        $outcome = SensitiveContent::scan((string) file_get_contents($file->getRealPath()), $detected);

        if ($outcome->isRefused() && $outcome->category !== null && $outcome->signal !== null) {
            /*
             * **Recorded, because a refusal that happened silently did not
             * happen.** PRD §4.6 and #100 item 3 both ask for it, and three
             * docblocks in this module claimed it while nothing wrote a line —
             * round 1 of review found it with `grep`, which is the right tool
             * for a promise nobody had asked to see.
             *
             * What it records is the **kind** of thing found and nothing else:
             * no filename, no matched string, no offset, no bytes. PRD §9
             * keeps PII out of logs, and the matched text of a refusal is by
             * definition the most sensitive string in the request.
             *
             * `reason_code`, never `reason` — `Redactor::SENSITIVE_KEY_PARTS`
             * holds `reason`, so a diagnostic under that key reaches the
             * operator as `[redacted]` and the entry says nothing at all.
             */
            Log::warning('document.refused', Redactor::context([
                'reason_code' => $outcome->signal,
                'category' => $outcome->category->value,
                'team_id' => $subject->getAttribute('team_id'),
                'actor_person_id' => $actor->getKey(),
                'mime_type' => $detected,
                'size_bytes' => (int) $file->getSize(),
            ]));

            throw RefusedDocument::detected($outcome->category, $outcome->signal);
        }

        return DB::transaction(function () use ($subject, $file, $actor, $category, $visibility, $outcome, $extension, $detected): Document {
            $teamId = $subject->getAttribute('team_id');

            $path = $file->storeAs(
                (string) $teamId,
                Str::ulid()->toString().'.'.$extension,
                ['disk' => self::DISK],
            );

            if (! is_string($path)) {
                throw UnsupportedDocument::unwritable();
            }

            $document = new Document;

            $document->forceFill([
                'documentable_type' => $subject->getMorphClass(),
                'documentable_id' => $subject->getKey(),
                'category' => $category,
                'disk' => self::DISK,
                'path' => $path,
                /*
                 * The name a person typed, kept for them and never used to
                 * build a key. Truncated rather than rejected: a long filename
                 * is not an error, and losing the upload over one would be.
                 */
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                // Detected from the bytes, never the browser's claim.
                'mime_type' => $detected,
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $this->nextSortOrder($subject),
                /*
                 * The first photo is the primary one. A property with photos
                 * and no primary renders no card image, which is the state
                 * somebody has to notice and fix by hand — so the common case
                 * arranges itself.
                 */
                'is_primary' => ! $this->hasAny($subject),
                'uploaded_by' => $actor->getKey(),
                /*
                 * F6.3: internal unless somebody says otherwise. The third
                 * place this default lives — the enum, the column, and here —
                 * because a default only the form knows is a default the next
                 * caller does not have.
                 */
                'visibility' => $visibility,
                /*
                 * What the scan actually did, which is not always *"passed"*.
                 * A file this build cannot look inside is recorded as
                 * `not_scanned`, because writing `clean` would put PRD §14.1
                 * Q6's *"guarantee that is not there"* on the row permanently.
                 */
                'scan_state' => $outcome->state(),
                'scanned_at' => now(),
            ])->save();

            return $document;
        });
    }

    /**
     * Read one file's contents for an authorized download.
     *
     * Deliberately **not** a URL. A presigned object-store URL is a second way
     * to read a file, and it is the way that cannot be audited: PRD §9 makes
     * document access an audited event, and an entry written when a link was
     * *minted* records an intention rather than a read. Streaming through the
     * application costs a hop and buys the only record that is true.
     */
    public function contents(Document $document): string
    {
        $contents = Storage::disk($document->disk)->get($document->path);

        return is_string($contents) ? $contents : '';
    }

    public function exists(Document $document): bool
    {
        return Storage::disk($document->disk)->exists($document->path);
    }

    /**
     * Remove the row and the bytes.
     *
     * The row soft-deletes for PRD §9's thirty-day window; the **file** goes
     * now. A soft-deleted row whose bytes are still readable by anything
     * holding the key is a deletion that did not delete, and the key is the
     * only thing the object store checks.
     */
    public function remove(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            Storage::disk($document->disk)->delete($document->path);

            $document->delete();
        });
    }

    private function nextSortOrder(Model $subject): int
    {
        return (int) Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->max('sort_order') + 1;
    }

    private function hasAny(Model $subject): bool
    {
        return Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->exists();
    }
}
