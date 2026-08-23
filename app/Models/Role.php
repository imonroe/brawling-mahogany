<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SystemRole;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An access role (PRD §4.2 F2.2, F2.3, §6.2).
 *
 * PRD §7.2 shrank this list to five genuine access tiers by moving Client,
 * Buyer, Seller and Service Provider to `deal_participants` — they are
 * relationships to one deal, not access levels, *"the same person sells in
 * March and buys in June."*
 *
 * `team_id` is null for the five system roles every team shares, and set for
 * the custom roles a team owner composes (F2.3).
 *
 * Deliberately not `BelongsToTeam`: a system role has no team, so the global
 * scope would hide the very rows every team needs. Visibility is expressed by
 * `availableTo()` instead, and the isolation suite records the exception.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 */
#[Fillable(['team_id', 'key', 'name', 'description', 'is_system'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /**
     * The roles a team can assign: the shared system five, plus its own.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableTo(Builder $query, Team $team): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->whereNull('team_id')
            ->orWhere('team_id', $team->getKey()));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignableWithinTeam(Builder $query, Team $team): Builder
    {
        // Super Administrator is a platform role. It is never handed out from
        // inside a team's own members screen, only by the super admin console.
        return $query->availableTo($team)->where('key', '!=', SystemRole::SuperAdministrator->value);
    }
}
