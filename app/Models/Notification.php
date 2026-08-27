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
     * This person's notifications, **across every team they are in**.
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPerson(Builder $query, Person $person): Builder
    {
        return $query->withoutGlobalScope(TeamScope::class)
            ->where('person_id', $person->getKey());
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
