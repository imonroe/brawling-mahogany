<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Models\KeyDate;

/**
 * What one write to the contingency calendar actually did (issue #106).
 *
 * The row that was edited, and every date that moved because of it. Returned
 * rather than flashed, because two screens need different halves: S18 redraws
 * the list, and the cascade dialog reports *"3 other dates moved"* — and a
 * count assembled from a flash message is a count that cannot be tested.
 */
final readonly class CascadeResult
{
    /**
     * @param  list<DateChange>  $moved  the **downstream** dates only; the
     *                                   edited row is `$keyDate`
     */
    public function __construct(
        public KeyDate $keyDate,
        public array $moved = [],
    ) {}

    public function movedCount(): int
    {
        return count($this->moved);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->keyDate->getKey(),
            'moved' => array_map(
                static fn (DateChange $change): array => $change->toArray(),
                $this->moved,
            ),
        ];
    }
}
