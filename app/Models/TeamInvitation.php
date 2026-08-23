<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An outstanding invitation (PRD §4.1 F1.3 · Screen Inventory S04, S74, S90).
 *
 * The plaintext token exists for exactly as long as it takes to put it in an
 * email. What is stored is its SHA-256 hash: an invitation link is a
 * credential while it lives, and a database dump should not be a set of
 * working keys.
 *
 * @property string $id
 * @property string $team_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $role_id
 * @property string|null $invited_by_person_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read Role $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['email', 'first_name', 'last_name', 'role_id', 'invited_by_person_id', 'expires_at'])]
class TeamInvitation extends Model
{
    /** @use HasFactory<TeamInvitationFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /** PRD F1.3 gives no number; a fortnight is long enough to be humane and short enough to be a credential. */
    public const LIFETIME_DAYS = 14;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'invited_by_person_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * S04's happy path. Expired, accepted, and revoked each get their own
     * screen rather than one generic failure — a person who clicks an old
     * link needs to know which of those happened.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
