<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonLifecycleState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TeamMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * **The person, as this team knows them** (PRD §4.1 F1.4, §6.2, §7.4 · #140).
 *
 * PRD §6.2 said *"team-private notes live here, not on the person"* and that
 * sentence turned out to describe the whole table rather than the notes
 * column. Slice 2 finished the thought: the name, the address, and the phone
 * number a team holds for somebody are as team-private as the notes, and they
 * live here too.
 *
 * `Person` is now only the login. Everything on a directory screen comes from
 * this row, which means one team cannot see what another typed — not because
 * a rule forbids it, but because there is no shared column holding it.
 *
 * A stager working for two teams is two membership rows. If they can sign in,
 * those rows point at one `people` row and they sign in once; if they cannot,
 * the rows point at their own credential-less `people` row each, because
 * there is nothing left for a shared one to share.
 *
 * @property string $id
 * @property string $team_id
 * @property string $person_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
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
    'first_name',
    'last_name',
    'email',
    'phone',
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
     * An address is stored folded to lower case.
     *
     * A team cannot hold the same address twice, and the unique index is over
     * `lower(email)` — so a contact typed as `Casey@Example.test` and one
     * typed as `casey@example.test` are the same directory entry, whatever the
     * mail client capitalised. Slice 1 shipped this bug on `people` and this
     * is the same fix in the table that now owns the column.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtolower(trim($value)),
        );
    }

    /** Display form, IA §10: First Last. */
    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /**
     * The surname a generated deal name uses (IA §10, F3.2).
     *
     * Null rather than a guess when there is no surname: "Bosart Purchase"
     * needs a Bosart, and a deal called " Purchase" is worse than one called
     * by its address.
     */
    public function surname(): ?string
    {
        $last = trim((string) $this->last_name);

        return $last === '' ? null : $last;
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
