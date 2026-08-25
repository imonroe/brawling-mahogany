<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     * What a photo may be, by extension and by type.
     *
     * An allowlist rather than a denylist, for the reason `SafeUrl` is one: a
     * denylist is a list of the attacks somebody thought of.
     *
     * @var array<string, string>
     */
    public const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
    ];

    /**
     * Store one file against a subject, and record it.
     *
     * @throws UnsupportedDocument when the file is not something this slice accepts
     */
    public function store(
        Model $subject,
        UploadedFile $file,
        Person $actor,
        DocumentCategory $category = DocumentCategory::Photo,
    ): Document {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::IMAGE_TYPES)) {
            throw UnsupportedDocument::extension($extension);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw UnsupportedDocument::tooLarge(self::MAX_BYTES);
        }

        return DB::transaction(function () use ($subject, $file, $actor, $category, $extension): Document {
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
                // Ours, from the extension allowlist — not the browser's claim.
                'mime_type' => self::IMAGE_TYPES[$extension],
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
