<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonLifecycleState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TeamMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * What one team knows about one person (PRD §4.1 F1.4, §6.2, §7.4).
 *
 * PRD §6.2: *"Team-private notes live here, not on the person."* That sentence
 * is the whole design. A stager working for two teams is one `people` row and
 * two memberships, and what Team A wrote about them is not Team B's business.
 *
 * @property string $id
 * @property string $team_id
 * @property string $person_id
 * @property PersonLifecycleState $status
 * @property bool $is_vendor
 * @property array<int, string>|null $vendor_specialties
 * @property int|null $vendor_typical_cost
 * @property string|null $vendor_service_area
 * @property int|null $vendor_rating
 * @property string|null $vendor_notes
 * @property string|null $notes
 * @property array<string, mixed>|null $role_fields
 * @property Carbon|null $joined_at
 * @property Carbon|null $revoked_at
 * @property-read Person $person
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'person_id',
    'status',
    'is_vendor',
    'vendor_specialties',
    'vendor_typical_cost',
    'vendor_service_area',
    'vendor_rating',
    'vendor_notes',
    'notes',
    'role_fields',
    'joined_at',
])]
class TeamMembership extends Model
{
    /** @use HasFactory<TeamMembershipFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PersonLifecycleState::class,
            'is_vendor' => 'boolean',
            'vendor_specialties' => 'array',
            'role_fields' => 'array',
            'joined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        /*
         * Trashed people included, deliberately.
         *
         * PRD §9 gives a deleted account a 30-day recovery window, and PRD
         * F1.3 keeps historical attribution through a revocation. A
         * soft-delete-scoped relation gave neither: the membership survived
         * and its person came back null, so the members screen, the export,
         * and the person detail each dereferenced it — and the members screen
         * is the only one that could have undone the membership.
         */
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_role');
    }

    /**
     * PRD F1.3: revoking preserves historical attribution. Every activity
     * event and audit entry this person authored still names them.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isRevoked()) {
            return false;
        }

        return in_array($key, $this->permissionKeys(), true);
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        $keys = [];

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $keys[$permission->key] = true;
            }
        }

        return array_keys($keys);
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->key === $key);
    }

    /**
     * Does this membership let somebody *act* in the team, or merely describe
     * somebody the team knows?
     *
     * The distinction is the whole reason the directory and the members
     * screen are separate screens with separate permissions. A client, a
     * vendor, and an opposing agent all hold a membership — that is what the
     * table is for — and none of them can open the dashboard. A Team Owner
     * holds one too, and removing theirs is an access change.
     *
     * The test is holding a role that carries at least one permission, the
     * same test `Person::activeTeams()` applies, so a team's own composed
     * roles (PRD F2.3) are covered without a list of keys to maintain.
     */
    public function carriesAccess(): bool
    {
        return $this->permissionKeys() !== [];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
