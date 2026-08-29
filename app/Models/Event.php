<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Calendar\Recurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something in the diary (PRD §4.8 F8.1 · S57, S58 · issue #105).
 *
 * A block of time somebody attends, which is what makes it not a key date —
 * see `EventType`. Every pointer out is nullable and each for its own reason:
 * an open house has a property and no deal, a closing appointment has both,
 * and a team meeting has neither.
 *
 * ## The whole model is written in UTC and read in the team's zone
 *
 * PRD §9, and it is load-bearing here rather than cosmetic. A 9am closing is
 * 9am **where the closing is**; a team member reading it from an airport must
 * not see it shift. `startsIn()` is the one place that conversion happens, so
 * the grid, the agenda list, the `.ics` feed and the deal timeline cannot
 * disagree about which square a thing falls in.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $deal_id
 * @property string|null $property_id
 * @property string|null $stage_id
 * @property EventType $type
 * @property string $title
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_all_day
 * @property string|null $location
 * @property array<int, mixed>|null $attendees
 * @property array<string, mixed>|null $recurrence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['title', 'description', 'location'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * How long an event with no end time is drawn as.
     *
     * A showing somebody entered without an end is still a block on a grid,
     * and drawing it as a zero-height sliver is how a dense day becomes
     * unreadable. An hour is what a showing usually is.
     */
    public const DEFAULT_MINUTES = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
            'attendees' => 'array',
            'recurrence' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function repeats(): ?Recurrence
    {
        return Recurrence::fromArray($this->recurrence);
    }

    /**
     * The membership ids this event says are coming.
     *
     * Ids, never names — the column holds pointers so a six-week-old event
     * shows the name the directory holds today, and so an `.ics` feed leaving
     * the building has no address in it to leak.
     *
     * @return list<string>
     */
    public function attendeeIds(): array
    {
        $ids = [];

        foreach ($this->attendees ?? [] as $attendee) {
            if (is_string($attendee) && trim($attendee) !== '') {
                $ids[] = $attendee;
            }
        }

        return array_values(array_unique($ids));
    }

    /** When this starts, on the team's wall clock. */
    public function startsIn(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::instance($this->starts_at)->setTimezone($timezone);
    }

    /**
     * When it ends, on the same clock, filling in an hour when nobody said.
     *
     * The fill happens here rather than in the grid because three surfaces ask
     * — the month grid, the week column, and the `.ics` `DTEND` — and an event
     * that is an hour long in the app and instantaneous in somebody's phone is
     * the kind of disagreement that costs a showing.
     */
    public function endsIn(string $timezone): CarbonImmutable
    {
        $start = $this->startsIn($timezone);

        if ($this->ends_at === null) {
            return $this->is_all_day
                ? $start->endOfDay()
                : $start->addMinutes(self::DEFAULT_MINUTES);
        }

        return CarbonImmutable::instance($this->ends_at)->setTimezone($timezone);
    }

    /**
     * Everything that could touch a window, before the recurrence is expanded.
     *
     * ## A recurring row is loaded whatever its start date
     *
     * A series that began in January still produces occurrences in September,
     * so narrowing on `starts_at` alone would drop exactly the rows the grid
     * needs. The rule is: a **one-off** must overlap the window, and a
     * **repeating** row is loaded if its series began before the window ends —
     * the expansion then decides which occurrences actually land, because that
     * is a question about the rule rather than about the row.
     *
     * `until` is deliberately not filtered in SQL. It lives inside a JSONB
     * document as a date string, and a comparison there would be a text
     * comparison that reads as a date comparison — the kind of thing that is
     * right until somebody stores a different format. `Recurrence` already
     * refuses to produce an occurrence past it, so the cost is loading a few
     * finished series and the benefit is one place that understands the rule.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTouching(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->where(fn (Builder $oneOff): Builder => $oneOff
                ->whereNull('recurrence')
                ->where('starts_at', '<=', $to)
                ->where(fn (Builder $ending): Builder => $ending
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $from)))
            ->orWhere(fn (Builder $repeating): Builder => $repeating
                ->whereNotNull('recurrence')
                ->where('starts_at', '<=', $to)));
    }
}
