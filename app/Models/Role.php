<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SystemRole;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Permissions;
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
     * Does holding this role put somebody **on the team**?
     *
     * #142's question, asked of the grant rather than of the membership. The
     * catalogue's surfaces are the answer: `Contact` and `Status Viewer` are
     * roles a *client* holds, and an invitation to one is not somebody joining
     * the team. Review on #162 found the lifecycle being cleared for every
     * accepted invitation, which erased a typed **Client** from the moment
     * somebody was given a status-page login.
     */
    public function grantsTeamAccess(): bool
    {
        /*
         * An archived role grants nothing. `holdsATeamSurfacePermission()`
         * excludes a soft-deleted role, and so does the relation behind
         * `carriesAccess()` — so a role that answered *yes* here while the
         * membership holding it answered *no* would put the two halves of one
         * question on opposite sides. Review on #162 measured exactly that:
         * accepting an invitation to a role archived in the meantime cleared
         * the lifecycle for a membership that then carried no access, leaving
         * a row with no badge of any kind.
         */
        if ($this->trashed()) {
            return false;
        }

        return Permissions::grantTeamAccess($this->permissionKeys());
    }

    /**
     * Every permission this role carries, deduplicated.
     *
     * `TeamMembership::permissionKeys()` is the same walk one level up and
     * calls this rather than repeating the loop.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        $this->loadMissing('permissions');

        $keys = [];

        foreach ($this->permissions as $permission) {
            $keys[$permission->key] = true;
        }

        return array_keys($keys);
    }

    /**
     * Who holds this role (S75 · #88).
     *
     * The count S75 shows **before** somebody archives a role, because a role
     * held by four people is a role whose archiving takes four people's
     * access with it — the same rule S76 set for deal types, where the in-use
     * count is shown before the choice rather than reported after it.
     *
     * @return BelongsToMany<TeamMembership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(TeamMembership::class, 'membership_role');
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
