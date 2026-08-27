<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\KeyDateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, legally significant date on a deal (PRD §4.8 F8.2 · issue #106).
 *
 * **`key_dates` in code, Dates & Deadlines in the UI, Important Dates to a
 * client** (IA §2). Three names for one thing, and IA §11 bans a fourth: a
 * deadline is never called a Milestone, which now means a moment on a stage
 * and nothing else.
 *
 * ## Nothing here writes itself
 *
 * `App\Support\Dates\SaveKeyDate` is the only writer, the way `RecordActivity`
 * is for `activity_events` — because writing a date is never only writing a
 * date. It recomputes everything derived from this one, reschedules the
 * automations and reminders hanging off it, and records what moved. A
 * controller that called `$keyDate->save()` would produce a correct row and an
 * incorrect calendar.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string $name
 * @property Carbon $date
 * @property bool $is_critical
 * @property string|null $anchor_key_date_id
 * @property int|null $offset_days
 * @property OffsetBasis|null $offset_basis
 * @property bool $is_derived
 * @property Carbon|null $detached_at
 * @property KeyDateSource $source
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property array<int, mixed>|null $reminder_offsets
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'date', 'is_critical', 'notes'])]
class KeyDate extends Model
{
    /** @use HasFactory<KeyDateFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * How far ahead an ordinary date is announced, in days (#109 · F8.4).
     *
     * A week, then the day before. Two reminders rather than one because the
     * first is *"start the thing"* and the second is *"it is now"*, and rather
     * than four because a date that emails somebody every day for a week is a
     * date whose emails get a filter rule.
     *
     * @var list<int>
     */
    public const DEFAULT_REMINDERS = [7, 1];

    /**
     * The same question for a critical date.
     *
     * #109: *"more aggressive treatment for `is_critical` dates"*, and PRD
     * §12.3 says why in one sentence — *"a missed inspection deadline is a
     * legal problem."* Two weeks out, then a week, then three days, then the
     * day itself. The day itself is the one an ordinary date does not get:
     * for a deadline with legal consequences, *today* is still actionable and
     * silence is the failure.
     *
     * @var list<int>
     */
    public const CRITICAL_REMINDERS = [14, 7, 3, 1, 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_critical' => 'boolean',
            'is_derived' => 'boolean',
            'detached_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'offset_basis' => OffsetBasis::class,
            'source' => KeyDateSource::class,
            'reminder_offsets' => 'array',
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
     * @return BelongsTo<self, $this>
     */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'anchor_key_date_id');
    }

    /**
     * Everything that would move if this date moved — one level of it.
     *
     * The transitive answer is `KeyDateGraph`'s, and deliberately not a
     * recursive relation: a cascade has to be computed once over the whole
     * deal, not walked a query at a time, and the walk has to detect a cycle
     * rather than recurse into one.
     *
     * @return HasMany<self, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'anchor_key_date_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'confirmed_by')->withTrashed();
    }

    /**
     * A proposal, not yet a date (Slice 5 · #116).
     *
     * The pair of columns, read in one place. `source` alone does not answer
     * it — an extracted date stays extracted after somebody confirms it, which
     * is the whole point of keeping provenance — and `confirmed_at` alone does
     * not either, because a date typed by hand is confirmed by having been
     * typed and carries no timestamp.
     *
     * A pending date is shown (somebody has to be able to agree to it) and is
     * **not counted as a deadline**: not in S59's next-14-days, not by the
     * `date_reached` gate, and not by the reminder sweep. #107 is explicit —
     * *"it must be visibly not-yet-real, and it must not be counted as a
     * deadline until confirmed."*
     */
    public function isPending(): bool
    {
        return $this->source === KeyDateSource::Extracted && $this->confirmed_at === null;
    }

    /**
     * Only dates on deals that are still running.
     *
     * The companion to `confirmed()`, and it belongs beside it for the same
     * reason: *"is this a deadline"* has two halves, and three readers were
     * answering with one. The reminder sweep filtered on `Deal::open()`, while
     * S59's Overdue tab, its count badge and the calendar grid did not — so
     * the screen an agent checks on Monday morning to see the week's exposure
     * accumulated every past deadline of every deal the team had ever closed,
     * growing without bound, while the emails about them had stopped months
     * before. A closed deal is not exposure.
     *
     * **Cross-deal readers only.** A closed deal's own Dates tab still lists
     * its dates — that is the record of what happened, and hiding it would
     * make the tab lie about a deal somebody is looking at deliberately.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnOpenDeals(Builder $query): Builder
    {
        return $query->whereIn('deal_id', Deal::query()->open()->select('id'));
    }

    /**
     * The opposite, as a query.
     *
     * Every reader that counts deadlines starts here, so the rule is written
     * once. Written as a `NOT (extracted AND unconfirmed)` rather than as
     * `manual OR confirmed`, because the two disagree the moment a third
     * source appears and only one of them stays correct.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $inner): Builder => $inner
                ->where('source', '!=', KeyDateSource::Extracted->value)
                ->orWhereNotNull('confirmed_at'),
        );
    }

    /**
     * Is this date still following its anchor?
     *
     * `is_derived` is the stored answer; this is the one readers ask, so that
     * *"derived"* never has to mean two things. A row with an anchor and
     * `is_derived = false` is a date that **used** to follow one and was
     * overridden — S18 says so, which is #106's *"stops following its anchor,
     * and says so."*
     */
    public function follows(): bool
    {
        return $this->is_derived
            && $this->anchor_key_date_id !== null
            && $this->offset_days !== null
            && $this->offset_basis instanceof OffsetBasis;
    }

    /** A date that was derived and has since been typed over. */
    public function wasDetached(): bool
    {
        return $this->detached_at !== null;
    }

    /**
     * Where this date would land, given where its anchor is.
     *
     * The only place the arithmetic happens. `OffsetBasis::apply()` owns the
     * weekend rule; this owns *which* day is being offset.
     */
    public function derivedFrom(CarbonInterface $anchorDate): CarbonInterface
    {
        $basis = $this->offset_basis ?? OffsetBasis::Calendar;

        return $basis->apply($anchorDate, $this->offset_days ?? 0);
    }

    /**
     * Which days ahead this date announces itself (#109).
     *
     * Null and `[]` are different answers, which is why the column is nullable
     * rather than defaulted in the migration: null is *"nobody has chosen"*
     * and takes the default for this kind of date, and `[]` is somebody having
     * deliberately turned the reminders off. A default written into the column
     * would make the second unreachable.
     *
     * Sorted descending and de-duplicated here rather than at each caller, so
     * the sweep can stop at the first one that has not been sent.
     *
     * @return list<int>
     */
    public function reminderDays(): array
    {
        $stored = $this->reminder_offsets;

        $days = $stored === null
            ? ($this->is_critical ? self::CRITICAL_REMINDERS : self::DEFAULT_REMINDERS)
            : array_values(array_map(
                static fn (mixed $day): int => (int) $day,
                array_filter($stored, static fn (mixed $day): bool => is_numeric($day) && (int) $day >= 0),
            ));

        $days = array_values(array_unique($days));

        rsort($days);

        return $days;
    }

    /**
     * Is this date in the past, in the team's calendar?
     *
     * The rule `Task::state()` arrived at over two rounds of review, applied
     * to a second table: a date **today** is not past due — somebody still has
     * the day to meet it — and *"today"* is a day where the team is, not where
     * UTC is. At 18:00 in Denver it is already tomorrow in UTC, and a deadline
     * that reads overdue while the reader still has six hours of their working
     * day is worse than no badge at all.
     *
     * Never reaches a client (IA §9: no alarming words).
     */
    public function isPastDue(): bool
    {
        return $this->date->toDateString() < self::today();
    }

    /**
     * Today, where the team is.
     *
     * Reads the resolved team rather than `$this->team`, which would be a
     * query per row on a list of forty. Falls back to the application's zone
     * for a console command sweeping every team — those pass the team's zone
     * in explicitly rather than relying on this.
     */
    public static function today(): string
    {
        $team = app(TeamContext::class)->get();

        $timeZone = $team instanceof Team ? $team->timezone : config('app.timezone');

        return CarbonImmutable::now(is_string($timeZone) ? $timeZone : 'UTC')->toDateString();
    }
}
