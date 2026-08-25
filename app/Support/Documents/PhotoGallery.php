<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ordering a subject's photos (S38 · issue #63).
 *
 * Reorder and set-primary, in one place for the reason `DealOffers` and
 * `DealTasks` are: setting one photo primary has to demote the incumbent, and
 * a controller that wrote the flag and forgot the demotion would look like it
 * worked right up until the partial unique index refused the second write.
 */
final class PhotoGallery
{
    /**
     * Put the photos in exactly this order.
     *
     * The whole list rather than a move-one endpoint, because a reorder is one
     * intention: two adjacent swaps racing each other produce an order neither
     * person chose, and the client already knows the arrangement it wants.
     *
     * Ids that are not this subject's are ignored rather than refused — a
     * stale tab reordering a photo somebody else deleted should still be able
     * to arrange the ones that are left.
     *
     * @param  list<string>  $ids
     */
    public function reorder(Model $subject, array $ids): void
    {
        DB::transaction(function () use ($subject, $ids): void {
            $photos = $this->for($subject)->keyBy(fn (Document $one): string => (string) $one->getKey());

            $position = 0;

            foreach ($ids as $id) {
                $photo = $photos->get($id);

                if ($photo instanceof Document) {
                    $photo->forceFill(['sort_order' => $position++])->save();
                }
            }
        });
    }

    /** The one that represents this subject. */
    public function setPrimary(Model $subject, Document $photo): void
    {
        DB::transaction(function () use ($subject, $photo): void {
            Document::query()
                ->where('documentable_type', $subject->getMorphClass())
                ->where('documentable_id', $subject->getKey())
                ->whereKeyNot($photo->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $photo->forceFill(['is_primary' => true])->save();
        });
    }

    /**
     * Whatever is left, still ordered, after one is removed.
     *
     * Promoting the first survivor rather than leaving none: a property with
     * photos and no primary renders no card image, which is a state somebody
     * has to notice and fix by hand.
     */
    public function afterRemoval(Model $subject): void
    {
        $remaining = $this->for($subject);

        if ($remaining->isEmpty() || $remaining->contains(fn (Document $one): bool => $one->is_primary)) {
            return;
        }

        // Non-empty by the guard above, so there is always a first.
        $this->setPrimary($subject, $remaining->first());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    public function for(Model $subject): \Illuminate\Database\Eloquent\Collection
    {
        return Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }
}
