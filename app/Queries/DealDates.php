<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OffsetBasis;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Support\Dates\KeyDateGraph;

/**
 * One deal's Dates & Deadlines, as S18 reads them (issue #107).
 *
 * The row shape is shared with S59's cross-deal list, which is the point:
 * *"derived dates show their anchor and offset, so a user can see **why** a
 * date is what it is"* is true on both screens, and two hand-rolled mappings
 * are how one of them stops saying it.
 */
final class DealDates
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forDeal(Deal $deal): array
    {
        $graph = KeyDateGraph::forDeal($deal);

        $dates = $graph->all();

        usort(
            $dates,
            static fn (KeyDate $a, KeyDate $b): int => [$a->date->toDateString(), $a->name]
                <=> [$b->date->toDateString(), $b->name],
        );

        return array_map(fn (KeyDate $date): array => $this->row($date, $graph), $dates);
    }

    /**
     * One row, with everything a screen needs to explain itself.
     *
     * @return array<string, mixed>
     */
    public function row(KeyDate $date, ?KeyDateGraph $graph = null): array
    {
        $anchor = $graph instanceof KeyDateGraph
            ? $graph->find($date->anchor_key_date_id)
            : $date->anchor;

        return [
            'id' => $date->getKey(),
            'name' => $date->name,
            'date' => $date->date->toDateString(),
            'isCritical' => $date->is_critical,
            'notes' => $date->notes,

            /*
             * Derived, detached, or neither — three states rather than a
             * boolean, because #106's *"stops following its anchor, **and says
             * so**"* needs the screen to tell a date that never had an anchor
             * apart from one that has been typed over.
             */
            'isDerived' => $date->follows(),
            'wasDetached' => $date->wasDetached(),
            'anchor' => $anchor instanceof KeyDate
                ? ['id' => $anchor->getKey(), 'name' => $anchor->name]
                : null,
            'offsetDays' => $date->offset_days,
            'offsetBasis' => $date->offset_basis?->value,
            /*
             * The sentence rather than the parts, composed once here.
             * *"10 calendar days after Mutual acceptance"* is the whole answer
             * to "why is this date what it is", and assembling it in the
             * component would put the pluralisation rule in a template.
             */
            'derivation' => $this->derivation($date, $anchor),

            /*
             * Slice 5's *extracted-pending* (#116). Both halves, because the
             * screen shows it as not-yet-real **and** excludes it from every
             * count — see `KeyDate::isPending()`.
             */
            'source' => $date->source->value,
            'isPending' => $date->isPending(),

            'reminderDays' => $date->reminderDays(),
            /*
             * Whether that schedule is stored or resolved from the default.
             * S18's dialog needs the distinction: a form that saw only the
             * resolved list would write today's default onto every date
             * somebody opened, and the row would stop following the rule the
             * moment it was marked critical.
             */
            'remindersAreSet' => $date->reminder_offsets !== null,
            'isPastDue' => $date->isPastDue() && ! $date->isPending(),
        ];
    }

    private function derivation(KeyDate $date, ?KeyDate $anchor): ?string
    {
        if ($date->offset_days === null) {
            return null;
        }

        $basis = $date->offset_basis ?? OffsetBasis::Calendar;

        /*
         * The anchor is gone — deleted, and the graph is built from live dates
         * so it cannot be found. The offset survives on the row, so the date
         * can still say **how far behind it ran** without naming a date that
         * no longer exists, which would only send somebody looking for it.
         *
         * Without this the row read exactly like a day somebody typed, which
         * is what the PRD entry for the detach behaviour says must not happen.
         */
        if (! $anchor instanceof KeyDate) {
            return $date->wasDetached()
                ? 'Was '.lcfirst($basis->phrase($date->offset_days)).' another date, which has since been removed'
                : null;
        }

        $phrase = $date->offset_days === 0
            ? 'The same day as '.$anchor->name
            : ucfirst($basis->phrase($date->offset_days)).' '.$anchor->name;

        return $date->follows() ? $phrase : 'Was '.lcfirst($phrase).', until somebody set it by hand';
    }
}
