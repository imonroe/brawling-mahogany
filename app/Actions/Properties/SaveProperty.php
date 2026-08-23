<?php

declare(strict_types=1);

namespace App\Actions\Properties;

use App\Models\ExternalLink;
use App\Models\Property;
use App\Support\Activity\RecordActivity;
use App\Support\Links\SafeUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a property and the links that hang off it (S37 · #61).
 *
 * One action rather than a controller doing both, because the two halves have
 * to succeed or fail together: a property saved with half its links replaced
 * is a record somebody has to repair by hand, and S37 presents them as one
 * form.
 */
final class SaveProperty
{
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<array-key, array<string, mixed>>|null  $links
     */
    public function create(array $attributes, ?array $links = null): Property
    {
        return DB::transaction(function () use ($attributes, $links): Property {
            $property = new Property;
            $property->fill($attributes);
            $this->saveOrExplainTheCollision($property);

            $this->syncLinks($property, $links ?? []);

            /*
             * Timelined, not audited. A property being added is ordinary work
             * a team reads back, not a security event — `CLAUDE.md` keeps
             * Activity and Audit apart on purpose, and they have different
             * readers and different retention.
             */
            $this->activity->record(
                subject: $property,
                eventType: 'property.added',
                summary: 'Added '.$property->displayName(),
            );

            return $property;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<array-key, array<string, mixed>>|null  $links  null leaves them alone
     */
    public function update(Property $property, array $attributes, ?array $links = null): Property
    {
        return DB::transaction(function () use ($property, $attributes, $links): Property {
            $property->fill($attributes);

            $statusChanged = $property->isDirty('status');
            $previousStatus = $property->getOriginal('status');

            $this->saveOrExplainTheCollision($property);

            /*
             * `null` and `[]` mean different things, and the difference is the
             * one #148 got wrong the first time: a request that did not send
             * `links` at all wants them left alone, and a request that sent an
             * empty list wants them all gone. `ConvertEmptyStringsToNull` does
             * not touch arrays, so the caller is the one that has to keep the
             * distinction — and `UpdatePropertyRequest` does, by presence.
             */
            if ($links !== null) {
                $this->syncLinks($property, $links);
            }

            if ($statusChanged) {
                // Named, like `property.added` above. A mixed timeline
                // reading "Sold" next to "Added 123 Main St" does not say
                // what was sold.
                $this->activity->record(
                    subject: $property,
                    eventType: 'property.status_changed',
                    summary: $property->displayName().' → '.$property->status->label(),
                    payload: ['from' => $previousStatus, 'to' => $property->status->value],
                );
            }

            return $property;
        });
    }

    /**
     * Make the stored links match what the form sent.
     *
     * Matched by id rather than replaced wholesale. Soft-deleting every link
     * and re-inserting it would have been shorter and would have churned a
     * row's identity on every save — and `records:purge` counts soft-deleted
     * rows, so editing a property's notes twice a week would have quietly
     * filled the table with tombstones.
     *
     * @param  array<array-key, array<string, mixed>>  $links
     */
    private function syncLinks(Property $property, array $links): void
    {
        $existing = $property->externalLinks()->get()
            ->keyBy(fn (ExternalLink $link): string => (string) $link->getKey());

        /*
         * `array_values`, because the loop's index becomes `sort_order`.
         *
         * `links` validates as an *array*, and a JSON body may key one however
         * it likes: `{"links": {"zz": {…}}}` passed every rule and then put
         * `"zz"` into an `unsignedSmallInteger`. `PropertyRules` now also
         * requires a `list`, so an HTTP caller is refused with a sentence —
         * this is the same guarantee for the seeder and for #62's screen,
         * which do not go through that request.
         */
        $links = array_values($links);

        /*
         * Deletions first, insertions second.
         *
         * The obvious order is the wrong one. `external_links_unique_url` is
         * partial on `deleted_at IS NULL`, so a row still live at insert time
         * blocks a resubmission of its own URL — and the UI's only way to edit
         * a link is to remove the row and add it back, which is exactly that
         * shape. Saving first refused "fix the label on this listing link"
         * with "this link is already on this property", about the row it was
         * replacing.
         */
        $kept = array_values(array_filter(array_map(
            fn (array $link): string => isset($link['id']) ? (string) $link['id'] : '',
            $links,
        )));

        $existing->reject(fn (ExternalLink $link): bool => in_array((string) $link->getKey(), $kept, true))
            ->each(fn (ExternalLink $link) => $link->delete());

        /*
         * An id may be claimed once. `$existing->get($id)` hands back the same
         * instance every time, so a payload repeating one silently wrote the
         * second row over the first and stored one link where two were sent.
         * `links.*.id` is `distinct` on the way in; this is the same rule for
         * the callers that are not that request.
         */
        $claimed = [];

        foreach ($links as $position => $link) {
            $id = isset($link['id']) ? (string) $link['id'] : '';
            $model = isset($claimed[$id]) ? null : $existing->get($id);
            $model ??= new ExternalLink;

            if ($model->exists) {
                $claimed[$id] = true;
            } else {
                /*
                 * `forceFill` for the pointer, `fill` for the content.
                 * `linkable_*` is not fillable for the same reason `team_id`
                 * is not: a request body must not choose what a row hangs
                 * off. The team comes from `BelongsToTeam`, and
                 * `ExternalLink`'s own guard refuses a target in another one.
                 */
                $model->forceFill([
                    'linkable_type' => $property->getMorphClass(),
                    'linkable_id' => $property->getKey(),
                ]);
            }

            $model->fill([
                'label' => trim((string) $link['label']),
                // Trimmed here rather than only by `TrimStrings`, because
                // `SafeUrl` trims before it judges and the stored value should
                // be the one that was judged.
                'url' => SafeUrl::normalise($link['url']),
                'sort_order' => $position,
            ]);

            $this->saveLinkOrExplainTheCollision($model, $position);
        }
    }

    /**
     * A parcel number is unique per team, and the index is what makes that
     * true — so this is where two people typing it at once is answered.
     *
     * The rule on the way in asks the same question; between its `select` and
     * this `insert` there is a window, and the answer somebody needs is the
     * sentence the rule would have given rather than a stack trace.
     */
    private function saveOrExplainTheCollision(Property $property): void
    {
        try {
            $property->save();
        } catch (UniqueConstraintViolationException) {
            /*
             * A different sentence from the rule's, deliberately.
             *
             * The rule answers the ordinary case; this only fires in the
             * window between its `select` and this `insert`, which means
             * somebody else got there in the last moment. Saying so is more
             * useful than repeating the rule — and it is what lets a test
             * tell which layer answered, without which a rule gap hides
             * behind this handler indefinitely.
             */
            throw ValidationException::withMessages([
                'parcel_number' => 'Somebody just added a property with this parcel number.',
            ]);
        }
    }

    private function saveLinkOrExplainTheCollision(ExternalLink $link, int $position): void
    {
        try {
            $link->save();
        } catch (UniqueConstraintViolationException) {
            // The rule names the ordinary duplicate; this is the race, and
            // the same argument for a distinct sentence applies.
            throw ValidationException::withMessages([
                "links.{$position}.url" => 'Somebody just added this link to this property.',
            ]);
        }
    }
}
