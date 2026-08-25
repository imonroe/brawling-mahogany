<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Document;
use App\Support\Documents\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A record that owns uploaded files (S38 · #63).
 *
 * ## Why a trait and not a line in the controller
 *
 * The same lesson `HasExternalLinks` records, and this is its second instance:
 * `documents.documentable_id` is polymorphic, so **no foreign key reaches
 * it** — there is nothing for `teamScopedForeign()` to cascade, and nothing
 * for the retention purge's `deleted_at` sweep to find, because a document
 * whose parent was deleted is not itself deleted. A rule written into
 * `PropertyController::destroy()` is a rule the second documentable is
 * written without, and Slice 3 makes deals documentable.
 *
 * Without it, deleting a property left its photos as live rows pointing at a
 * parent that no longer exists, and their bytes on the disk **permanently** —
 * past PRD §9's *"then hard delete"*, and past F6.4's promise that a deletion
 * deletes.
 *
 * ## Through the service, because the bytes are the point
 *
 * `DocumentStorage::remove()` deletes the file and soft-deletes the row, in
 * that order and in one transaction. Calling `$this->documents()->delete()`
 * here would leave every file on the disk — which is the bug this trait
 * exists to close, reintroduced one layer down.
 *
 * @phpstan-require-extends Model
 */
trait HasDocuments
{
    // `isForceDeleting()` below is declared rather than assumed.
    use SoftDeletes;

    public static function bootHasDocuments(): void
    {
        static::deleting(function (self $model): void {
            $storage = app(DocumentStorage::class);

            /*
             * `withTrashed()` on a force delete, because by then the rows this
             * model soft-deleted on its way here are trashed and their files
             * are already gone — `remove()` is idempotent on a missing file,
             * and skipping them would strand the bytes of anything deleted in
             * between.
             */
            $query = Document::query()
                ->where('documentable_type', $model->getMorphClass())
                ->where('documentable_id', $model->getKey());

            if ($model->isForceDeleting()) {
                $query->withTrashed();
            }

            foreach ($query->get() as $document) {
                $storage->remove($document);
            }
        });
    }
}
