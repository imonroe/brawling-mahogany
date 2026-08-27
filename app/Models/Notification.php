<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Models\Concerns\TeamScope;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a person has been told (S08 · F12.4 · issue #101).
 *
 * @property string $id
 * @property string $team_id
 * @property string $person_id
 * @property NotificationType $type
 * @property string|null $deal_id
 * @property string $summary
 * @property array<string, mixed>|null $data
 * @property list<string>|null $channels
 * @property Carbon|null $deliver_after
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
#[Fillable([])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'channels' => 'array',
            'deliver_after' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
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
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * This person's notifications, across every team they are **still** in.
     *
     * The one read in the product that lifts the team scope on purpose, and
     * issue #101 asks for it: *"a person in two teams needs to know which one
     * a notification came from, and switching teams should not hide it."* A
     * stager working two agencies who is told at nine that a task is theirs
     * must not lose it by switching to the other team at ten.
     *
     * `UnscopedQueryConventionTest` records it as kind 1 — a question about the
     * **actor**. It is not a read of tenant data through a hole: the predicate
     * is the person's own id, the rows are ones addressed to them, and the
     * team each belongs to is shown on the line rather than hidden.
     *
     * ## The membership predicate, and what its absence cost
     *
     * Round 3 of review found the sentence above true of **which rows** and
     * false of **what they say**. `summary` is snapshotted at raise time by
     * `Notify::line()`, but `NotificationFeed` hydrates `dealName` and
     * `teamName` **live** on every load — so a person whose membership was
     * revoked, and who still holds an account because they are in another
     * team, went on receiving team A's current deal names indefinitely.
     * Measured: the membership revoked, the deal then renamed, and the feed
     * returned the new name.
     *
     * `deals.name` falls back to `generated_name`, which `NameDeal` derives
     * from the subject property's address and the roster — so for the ordinary
     * deal that is a client's address and a client's name, delivered to
     * somebody the team removed. An **unread** notification is never purged
     * (`records:purge` sweeps on `read_at`), so it does not age out either.
     *
     * `open()` already knew revocation mattered — it refuses the team switch
     * unless `activeTeams()` still contains the team. The predicate belongs
     * here rather than there, because putting it at one call site is
     * `CLAUDE.md`'s *"a rule enforced at call sites is enforced at some call
     * sites"*: the feed, `ShellCounts`' badge, `read()` and `open()` are four
     * readers, and the fourth is the one written without the rule.
     *
     * A subquery rather than a loaded list of ids, so `ShellCounts` stays the
     * one round trip `PeopleIndexBudgetTest` holds it to.
     *
     * ## Revocation only, and deliberately not the rest of `activeTeams()`
     *
     * `Person::activeTeams()` asks three further things — the membership
     * carries a team-surface permission, the team is not suspended, the team
     * is not deleted. None of them belongs here, and `carryingAccess()` is the
     * one worth naming because adding it was tried and reverted: `Notify`
     * writes a row for a task's assignee whatever roles they hold, so reading
     * more strictly than writing would manufacture rows nobody can ever see —
     * `CLAUDE.md`'s *"a row nothing can reach"*, created by the fix for a
     * leak.
     *
     * That is also what keeps `open()`'s own `activeTeams()` check honest
     * rather than dead: a suspended team, a deleted one, or a membership
     * stripped of its roles all still reach it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPerson(Builder $query, Person $person): Builder
    {
        return $query->withoutGlobalScope(TeamScope::class)
            ->where('person_id', $person->getKey())
            ->whereIn('team_id', TeamMembership::withoutTeamScope()
                ->select('team_id')
                ->where('person_id', $person->getKey())
                ->whereNull('revoked_at'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Owed an email or a push, and past whatever held it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        return $query->whereNull('delivered_at')
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('deliver_after')
                ->orWhere('deliver_after', '<=', $at));
    }

    /**
     * The channels this owes, beyond the row itself.
     *
     * @return list<NotificationChannel>
     */
    public function outboundChannels(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?NotificationChannel => NotificationChannel::tryFrom($value),
            $this->channels ?? [],
        ), static fn (?NotificationChannel $channel): bool => $channel?->reachesOut() ?? false));
    }

    /**
     * Where this points, when it points anywhere.
     *
     * Composed rather than stored, because it is a route: a stored URL is a
     * copy of `routes/web.php` that nothing updates when a path changes, and
     * this table will outlive at least one such change.
     */
    public function url(): ?string
    {
        if ($this->deal_id === null) {
            return null;
        }

        $tab = match ($this->type) {
            NotificationType::TaskAssigned,
            NotificationType::DeadlineApproaching => '/tasks',
            NotificationType::AutomationFailed => '/timeline',
            default => '',
        };

        return '/deals/'.$this->deal_id.$tab;
    }
}
