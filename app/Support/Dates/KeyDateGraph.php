<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Models\Deal;
use App\Models\KeyDate;
use Carbon\CarbonInterface;

/**
 * The anchor chain of one deal, walked (PRD §4.8 F8.2 · issue #106).
 *
 * F8.2's hard half is not the arithmetic — it is *"downstream recalculation
 * when an anchor moves"*, transitively, previewable, and with cycles
 * impossible. All three are questions about a graph, so the graph is a thing
 * rather than a loop inside a service.
 *
 * ## Loaded once, walked in memory
 *
 * A deal holds a handful of dates. Walking them with a query per hop would be
 * one round trip per link of a chain that is usually three long and is read on
 * every save — and, worse, a query-per-hop walk has no cheap way to notice it
 * is going round in a circle. Reading the deal's dates once makes both the
 * cascade and the cycle check ordinary array work.
 *
 * ## Why the cascade is computed and returned rather than applied
 *
 * #106 wants a preview that is *"accurate against"* the apply. The only way to
 * guarantee that is for the preview and the apply to be the same computation,
 * so this returns a list of {@see DateChange} and writes nothing at all.
 * `SaveKeyDate` is what persists one.
 */
final class KeyDateGraph
{
    /**
     * @param  array<string, KeyDate>  $dates  keyed by id
     */
    private function __construct(private readonly array $dates) {}

    public static function forDeal(Deal $deal): self
    {
        return self::of(KeyDate::query()->where('deal_id', $deal->getKey())->get()->all());
    }

    /**
     * @param  iterable<int, KeyDate>  $dates
     */
    public static function of(iterable $dates): self
    {
        $keyed = [];

        foreach ($dates as $date) {
            $keyed[(string) $date->getKey()] = $date;
        }

        return new self($keyed);
    }

    /**
     * Every date this deal holds, in the order the graph knows them.
     *
     * @return list<KeyDate>
     */
    public function all(): array
    {
        return array_values($this->dates);
    }

    public function find(?string $id): ?KeyDate
    {
        if ($id === null) {
            return null;
        }

        return $this->dates[$id] ?? null;
    }

    /**
     * What moves if `$moved` lands on `$to`.
     *
     * Breadth-first from the moved date outwards, which is a valid
     * recalculation order because a date depends on exactly one other date:
     * by the time a row is visited, its anchor's new value is already known.
     *
     * ## Every dependent is recomputed; only the ones that move are returned
     *
     * A business-day offset off an anchor that moved from Saturday to Monday
     * does not move. Returning it anyway would put rows in the preview that
     * change nothing, and #106's whole argument for the preview is that
     * somebody reads it before agreeing.
     *
     * ## A detached date stops the walk
     *
     * `KeyDate::follows()` is false for a derived date somebody has typed
     * over, so it is not recomputed — and nothing behind it is either, because
     * its own value did not change. That is what *"stops following its
     * anchor"* has to mean to be worth anything: an override that still got
     * dragged around by the anchor would be an override in name only.
     *
     * ## The visited set is a cycle backstop, not the cycle check
     *
     * {@see self::wouldLoop()} refuses a cycle at the write, and the migration
     * refuses the one-row case. This is the third layer, and it exists because
     * a cycle reaching this method would be an infinite loop inside a web
     * request — the one failure mode worth spending a `array_key_exists` per
     * row to make impossible.
     *
     * @return list<DateChange>
     */
    public function cascadeFrom(KeyDate $moved, CarbonInterface $to): array
    {
        $newDates = [(string) $moved->getKey() => $to->startOfDay()];

        $changes = [];
        $visited = [(string) $moved->getKey() => true];
        $queue = [(string) $moved->getKey()];

        while ($queue !== []) {
            $anchorId = array_shift($queue);

            foreach ($this->dependentsOf($anchorId) as $dependent) {
                $id = (string) $dependent->getKey();

                if (isset($visited[$id])) {
                    continue;
                }

                $visited[$id] = true;

                $landsOn = $dependent->derivedFrom($newDates[$anchorId]);

                $newDates[$id] = $landsOn->startOfDay();

                $change = new DateChange($dependent, $dependent->date->startOfDay(), $landsOn);

                if ($change->moves()) {
                    $changes[] = $change;
                }

                /*
                 * Queued whether or not it moved. A date that did not move
                 * still anchors dates that may: a dependent counted in
                 * business days off it can land differently even when its own
                 * anchor's arithmetic came out the same, and stopping the walk
                 * at an unchanged row would silently truncate the chain.
                 */
                $queue[] = $id;
            }
        }

        return $changes;
    }

    /**
     * Would anchoring `$date` to `$anchorId` make a loop?
     *
     * Walks **up** from the proposed anchor. If the chain reaches the row
     * being edited, the edit would close a circle. Cheaper and clearer than
     * walking down from the row: upwards there is exactly one edge per node,
     * so the walk is a straight line and terminates in the length of the
     * chain.
     *
     * The second `isset($seen)` guard catches a cycle that is already in the
     * table — which cannot happen through `SaveKeyDate`, and can happen
     * through a hand-written UPDATE, a restored backup, or a future importer.
     * A method whose job is to prevent an infinite loop must not contain one.
     */
    public function wouldLoop(KeyDate $date, ?string $anchorId): bool
    {
        if ($anchorId === null) {
            return false;
        }

        if ($anchorId === (string) $date->getKey()) {
            return true;
        }

        $seen = [];
        $cursor = $this->find($anchorId);

        while ($cursor instanceof KeyDate) {
            $id = (string) $cursor->getKey();

            if ($id === (string) $date->getKey()) {
                return true;
            }

            if (isset($seen[$id])) {
                return true;
            }

            $seen[$id] = true;

            $cursor = $this->find($cursor->anchor_key_date_id);
        }

        return false;
    }

    /**
     * The dates that may legally be an anchor for this one (S18's picker).
     *
     * Everything on the deal except the row itself and anything that already
     * depends on it — which is the same question `wouldLoop()` answers, asked
     * of every candidate at once so the editor can hide the ones that would be
     * refused rather than refuse them after the fact.
     *
     * @return list<KeyDate>
     */
    public function anchorCandidatesFor(?KeyDate $date): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (KeyDate $candidate): bool => ! $date instanceof KeyDate
                || (! $candidate->is($date) && ! $this->wouldLoop($date, (string) $candidate->getKey())),
        ));
    }

    /**
     * @return list<KeyDate>
     */
    private function dependentsOf(string $anchorId): array
    {
        return array_values(array_filter(
            $this->dates,
            fn (KeyDate $date): bool => $date->anchor_key_date_id === $anchorId && $date->follows(),
        ));
    }
}
