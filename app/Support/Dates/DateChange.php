<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Models\KeyDate;
use Carbon\CarbonInterface;

/**
 * One date moving, as the preview shows it (issue #106 · S18).
 *
 * #106: *"a cascade must be previewable before it is applied. Moving a closing
 * date by three days can move eleven other dates, and the user must see that
 * before agreeing."* A preview is a list of these, and the same list is what
 * gets applied — so what somebody agreed to and what happened cannot differ.
 *
 * Carries the row rather than only its id, because the preview names the date
 * (*"Inspection objection"*) and says whether it is critical, and re-reading
 * eleven rows to render a dialog the graph has already loaded would be work
 * with no reader.
 */
final readonly class DateChange
{
    public function __construct(
        public KeyDate $keyDate,
        public CarbonInterface $from,
        public CarbonInterface $to,
    ) {}

    /**
     * Did this actually move?
     *
     * A cascade recomputes every dependent, and most recomputations land on
     * the day the row already holds — an anchor that moved from a Saturday to
     * the Monday after it does not move a business-day offset at all. Those
     * are dropped rather than shown: a preview listing eleven dates of which
     * three change is a preview somebody stops reading.
     */
    public function moves(): bool
    {
        return $this->from->toDateString() !== $this->to->toDateString();
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay(), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->keyDate->getKey(),
            'name' => $this->keyDate->name,
            'isCritical' => $this->keyDate->is_critical,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
        ];
    }
}
