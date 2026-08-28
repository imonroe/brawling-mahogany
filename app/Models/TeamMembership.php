<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonLifecycleState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Permissions;
use Closure;
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
 * @property ?PersonLifecycleState $status
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
            foreach ($role->permissionKeys() as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Does this membership hold the **shipped** role with this key?
     *
     * `team_id === null` is the load-bearing half, and leaving it out was a
     * real hole rather than a tidy-up. `RoleController` derives a new role's
     * key from its name, so a team composing one called *"Team Owner"* got
     * `team_owner` — a key the unique index over `(team_id, key)` permits,
     * because the shipped row's `team_id` is null. Matching on key alone then
     * made that counterfeit indistinguishable from the real thing wherever the
     * product asks *"is this person an owner"*, and the sharpest consequence
     * was `RevokeMembership`'s last-owner guard counting it: revoke the only
     * genuine owner and the team is locked out of its own settings.
     *
     * Refusing the colliding name is the other half and lives in the
     * controller. This is the half that holds however the row got there.
     */
    public function hasRole(string $key): bool
    {
        return $this->roles->contains(
            fn (Role $role): bool => $role->key === $key && $role->team_id === null,
        );
    }

    /**
     * The same question as a scope, for the callers that count rather than ask.
     *
     * `hasRole()` reads a loaded collection; this is the query, and both had to
     * learn the `team_id` half at once — the memberships screen refuses to
     * revoke the last owner by asking `hasRole()`, and decides who *else* is
     * one with a query. A guard whose two halves disagree about what an owner
     * is refuses the wrong person and permits the wrong revoke.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHoldingSystemRole(Builder $query, string $key): Builder
    {
        return $query->whereHas(
            'roles',
            fn ($roles) => $roles->where('roles.key', $key)->whereNull('roles.team_id'),
        );
    }

    /**
     * `hasPermission()`, asked of a query rather than of a loaded row.
     *
     * The same walk the model does — roles, then their permissions, then the
     * key — for a caller that cannot load a membership to ask it of.
     * `ChecksTeamPermissions::allows()` is the shape being mirrored, revoked
     * check included: `hasPermission()` returns false for a revoked membership
     * before it looks at a single role, and a scope that left that out would
     * answer *yes* for somebody who left in March.
     *
     * *Mirrors the walk*, deliberately, rather than *"gives the same answer as
     * a policy"* — that would be a claim about two things this method does not
     * control. A policy has a resolved tenant and this has whatever the caller
     * narrowed to (below), and the two run the traversal through different
     * machinery, so an absolute parity claim is one nothing checks. What is
     * asserted is the part that can be: the same three steps and the same
     * revoked check.
     *
     * Note this is a **specific key**, not `carryingAccess()`'s *"any key on
     * the team surface"*. The two are different questions and the difference
     * is the whole of #194: a person can hold a composed role that reaches the
     * app and does not reach the calendar.
     *
     * What it does **not** decide is *which team is being asked about*. A
     * policy knows, because `TeamContext` resolved one; a caller reaching for
     * this scope usually has not, which is why it exists. So narrowing to the
     * right membership is the caller's, and a caller that skips it asks
     * whether the person holds the key **anywhere** — which for somebody
     * working at two agencies is a permission granted by one team answering
     * for another. `ManageCalendarFeeds::findByToken()` correlates on
     * `calendar_feeds.team_id`, and `CalendarFeedsTest` fails if it stops.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHoldingPermission(Builder $query, string $key): Builder
    {
        return $query
            ->whereNull('team_memberships.revoked_at')
            ->whereHas('roles', self::holdsOneOf([$key]));
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
     * **The test is holding a role that carries at least one permission on the
     * team surface** (`App\Enums\PermissionSurface`), which is issue #142's
     * answer and a narrowing of Slice 1's *"at least one permission"*.
     *
     * Two things follow, and both were broken:
     *
     *  - A team's own composed role (PRD F2.3) is covered without a list of
     *    keys to maintain, because the answer comes from what the role is made
     *    of. Slice 1 had this property here and lost it in four queries
     *    across three files, each of which kept its own
     *    `['team_owner', 'team_member']`; they all call the scopes below now.
     *  - A role that grants only the *client* surface — Status Viewer, once
     *    #110 gives it `status_page.view` — is still not team access. Under
     *    "any permission at all" it would have become one, and removing a
     *    client from the directory would silently have started needing
     *    `team.members.manage`, which the person who tidies the directory does
     *    not have.
     *
     * Deliberately says nothing about revocation. A revoked Team Owner's
     * membership still *is* an access membership, which is why
     * `PersonController::destroy()` still routes it through
     * `RevokeMembership` rather than deleting it out from under PRD F1.3's
     * historical attribution.
     */
    public function carriesAccess(): bool
    {
        return Permissions::grantTeamAccess($this->permissionKeys());
    }

    /**
     * Somebody the team **works with now** — the question every screen asks.
     *
     * `carriesAccess()` deliberately says nothing about revocation, which is
     * right for authorization and wrong for a directory: a revoked Team Owner
     * still holds an access membership, and is no longer a colleague. IA §8 is
     * where the rule is written down; this is the one place it is expressed in
     * code, and `scopeNotColleagues()` below is the same question in SQL.
     */
    public function isColleague(): bool
    {
        return $this->carriesAccess() && ! $this->isRevoked();
    }

    /**
     * `carriesAccess()`, asked of a query rather than of a loaded row.
     *
     * The same question in SQL, so the members screen (S74), the People
     * index's Team segment (S30), the console's team detail, and the team
     * switcher all get the same answer as the model does. All but the switcher
     * used to name role keys instead, and the answers only agreed by
     * coincidence. The Clients segment asks the inverse, below.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCarryingAccess(Builder $query): Builder
    {
        return $query->whereHas('roles', self::holdsATeamSurfacePermission());
    }

    /**
     * The other side of the same question, for the Clients segment.
     *
     * `whereDoesntHave` over the *same* constraint object, not a second copy
     * of it — so **the two scopes** are one condition and its negation, and
     * cannot drift apart.
     *
     * That is a claim about the scopes and not about the screens. The People
     * index's tabs add their own filters on top — Clients narrows to two
     * lifecycle states, Team excludes revoked memberships — so a revoked Team
     * Owner is on neither tab, correctly and by those filters rather than by
     * anything here.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotCarryingAccess(Builder $query): Builder
    {
        return $query->whereDoesntHave('roles', self::holdsATeamSurfacePermission());
    }

    /**
     * `isColleague()`, asked of a query rather than of a loaded row.
     *
     * The two scopes below are the pair the People index segments use, and
     * they exist because the badge and the segment beside it were asking
     * different questions. The badge asked `isColleague()`; Clients and Leads
     * asked `notCarryingAccess()`, which revocation does not enter — so a
     * former colleague recorded as the past client they now are was drawn as a
     * contact and filtered as a colleague, and appeared on no segment but All.
     * Review on #162 measured it through the routes.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeColleagues(Builder $query): Builder
    {
        return $query->whereNull('team_memberships.revoked_at')->carryingAccess();
    }

    /**
     * Everybody the lifecycle can honestly describe: a contact of this team,
     * or somebody who used to be a colleague and is one of those two things
     * now.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotColleagues(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->whereNotNull('team_memberships.revoked_at')
            ->orWhere(fn (Builder $access): Builder => $access->notCarryingAccess()));
    }

    /**
     * The condition both scopes are made of: a role holding at least one
     * permission on the team surface.
     *
     * @return Closure(Builder<Role>): Builder<Role>
     */
    private static function holdsATeamSurfacePermission(): Closure
    {
        return self::holdsOneOf(Permissions::teamSurfaceKeys());
    }

    /**
     * The walk itself: a role holding at least one of these permission keys.
     *
     * Shared by `carryingAccess()`'s *"any key on the team surface"* and
     * `holdingPermission()`'s *"this key"*, which are different questions over
     * one traversal. Written twice in the round that added the second, until
     * review pointed out that two copies of a permission walk is how the two
     * start answering differently.
     *
     * @param  list<string>  $keys
     * @return Closure(Builder<Role>): Builder<Role>
     */
    private static function holdsOneOf(array $keys): Closure
    {
        return fn (Builder $roles): Builder => $roles->whereHas(
            'permissions',
            fn (Builder $permissions): Builder => $permissions->whereIn('permissions.key', $keys),
        );
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
