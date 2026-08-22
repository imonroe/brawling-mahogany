<?php

declare(strict_types=1);

namespace App\Actions\Properties;

use App\Models\ExternalLink;
use App\Models\Property;
use App\Support\Activity\RecordActivity;
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
     * @param  list<array<string, mixed>>|null  $links
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
     * @param  list<array<string, mixed>>|null  $links  null leaves them alone
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
                $this->activity->record(
                    subject: $property,
                    eventType: 'property.status_changed',
                    summary: $property->status->label(),
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
     * @param  list<array<string, mixed>>  $links
     */
    private function syncLinks(Property $property, array $links): void
    {
        $existing = $property->externalLinks()->get()
            ->keyBy(fn (ExternalLink $link): string => (string) $link->getKey());

        $kept = [];

        foreach ($links as $position => $link) {
            $id = isset($link['id']) ? (string) $link['id'] : '';
            $model = $existing->get($id) ?? new ExternalLink;

            if (! $model->exists) {
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
                'label' => (string) $link['label'],
                'url' => (string) $link['url'],
                'sort_order' => $position,
            ]);

            $this->saveLinkOrExplainTheCollision($model, $position);

            $kept[] = $model->getKey();
        }

        /*
         * Everything the form did not send back is gone. Soft, so the 30-day
         * window (PRD §9) applies here like everywhere else.
         */
        $property->externalLinks()->whereKeyNot($kept)->get()
            ->each(fn (ExternalLink $link) => $link->delete());
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
            throw ValidationException::withMessages([
                'parcel_number' => 'Another property already has this parcel number.',
            ]);
        }
    }

    private function saveLinkOrExplainTheCollision(ExternalLink $link, int $position): void
    {
        try {
            $link->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                "links.{$position}.url" => 'This link is already on this property.',
            ]);
        }
    }
}
