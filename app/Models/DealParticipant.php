<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParticipantRole;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DealParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody's part in one deal (PRD §4.3 F3.3, §7.2 · S19, S25 · issue #60).
 *
 * **Not an access role.** PRD §7.2 is emphatic that Client, Buyer, Seller and
 * Service Provider describe a relationship to a transaction rather than
 * permission to use the software — and calls separating them *"the single
 * biggest simplification available"*. The same person sells in March and buys
 * in June, and neither fact says anything about what they may open.
 *
 * The row points at a `team_memberships` id rather than a `people` id, which
 * is a deviation from PRD §6.2 that #140 forced; the migration argues it in
 * full.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string $team_membership_id
 * @property ParticipantRole $participant_role
 * @property bool $is_primary
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['participant_role', 'is_primary', 'notes'])]
class DealParticipant extends Model
{
    /** @use HasFactory<DealParticipantFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participant_role' => ParticipantRole::class,
            'is_primary' => 'boolean',
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
     * The directory entry this participant is.
     *
     * `withTrashed()` for the same reason `TeamMembership::person()` carries
     * it: a participant outlives the directory row being tidied away, and a
     * deal that renders a blank line where a name was is worse than one that
     * still says who the seller was.
     *
     * @return BelongsTo<TeamMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TeamMembership::class, 'team_membership_id')->withTrashed();
    }

    /** What to call this participant on a screen. */
    public function fullName(): string
    {
        return $this->membership->fullName();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInRole(Builder $query, ParticipantRole $role): Builder
    {
        return $query->where('participant_role', $role);
    }
}
