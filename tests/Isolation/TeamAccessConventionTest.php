<?php

declare(strict_types=1);

use App\Enums\PermissionSurface;
use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Queries\PeopleDirectory;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * One definition of "is on the team", asserted rather than remembered (#142).
 *
 * `TeamMembership::carriesAccess()` separates somebody who can **act** in a
 * team from somebody the team merely **knows**, and the separation is load
 * bearing: it decides who appears on `/settings/members` (S74), who is under
 * the People index's Team segment (S30), which teams the switcher offers, and
 * — sharpest of all — whether removing somebody from the directory is a
 * directory edit or a revocation needing `team.members.manage`.
 *
 * Slice 1 answered it structurally (any permission at all) in two places —
 * `carriesAccess()` and `Person::activeTeams()` — and with a hard-coded
 * `['team_owner', 'team_member']` in four queries across three files: the
 * members screen, both halves of the People index's segment filter, and the
 * console's team detail. Nothing was wrong, because the shipped roles happened
 * to line up. Two things were about to make them stop:
 *
 *  - **A team composing its own role** (PRD F2.3, screen #88) gets somebody
 *    who can act in the team and appears on neither list, so they cannot be
 *    revoked from the screen that revokes people.
 *  - **Status Viewer gaining a permission** in Slice 4 (#110) flips
 *    `carriesAccess()` to true for every client at once, and tidying the
 *    directory silently becomes an access operation.
 *
 * The answer is `App\Enums\PermissionSurface`: a permission belongs to the
 * team app, the client status page, or the platform console, and team access
 * means holding at least one on the team surface. This file is what stops that
 * from drifting — the way `ModelTenancyConventionTest` stops a model from
 * shipping without a tenancy decision. **A role added without a decision fails
 * the build.**
 */

/**
 * Every shipped role, and whether it puts somebody on the team.
 *
 * Every entry needs a reason. This is the decision, written down; the tests
 * below check that the seeded permission sets actually produce it, so a change
 * to `Permissions::forSystemRoles()` that contradicts a line here fails rather
 * than quietly redefining what a team member is.
 *
 * @var array<string, array{access: bool, reason: string}>
 */
const SYSTEM_ROLE_TEAM_ACCESS = [
    SystemRole::TeamOwner->value => [
        'access' => true,
        'reason' => 'Runs the team. The role the last-owner rule protects.',
    ],

    SystemRole::TeamMember->value => [
        'access' => true,
        'reason' => 'Works deals day to day. The ordinary seat.',
    ],

    SystemRole::StatusViewer->value => [
        'access' => false,
        'reason' => 'A client reading one status page through a magic link (Slice 4, #110). '.
            'Never signs into the team app. This is the entry the surface distinction '.
            'exists for: when #110 hands the role `status_page.view`, the permission is '.
            'on the client surface, so this stays false and removing a client from the '.
            'directory stays a directory edit rather than becoming a revocation that '.
            'needs team.members.manage.',
    ],

    SystemRole::Contact->value => [
        'access' => false,
        'reason' => 'Known to the team, with no access of any kind. Holds no permissions.',
    ],

    SystemRole::SuperAdministrator->value => [
        'access' => false,
        'reason' => 'A platform role, never assignable from inside a team '.
            '(Role::assignableWithinTeam). Its power over a team is the console and '.
            'audited impersonation, not a seat on the team — so it is Platform surface, '.
            'and the members screen goes on excluding it exactly as it did when it '.
            'named its two keys by hand.',
    ],
];

/**
 * Deciding who is on the team by naming role keys, which is the mistake.
 *
 * Comments are stripped and string literals are kept — the opposite of
 * `UnscopedQueryConventionTest`, and for the opposite reason: there the
 * evidence is a method call, here it is the key itself. Prose about
 * `team_owner` is fine; a query filtering on it is what this counts.
 *
 * @var array<string, array{count: int, reason: string}>
 */
const SANCTIONED_TEAM_ROLE_KEY_USES = [
    'Support/Permissions.php' => [
        'count' => 5,
        'reason' => 'The definition itself: which permissions each shipped role holds. '.
            'Five, because forSystemRoles() names all five roles.',
    ],

    'Actions/Teams/RevokeMembership.php' => [
        'count' => 3,
        'reason' => 'The last-owner rule, which is a different question with a different '.
            'answer. "Can this person act in the team" is what carriesAccess() decides; '.
            '"is this the last person who can administer it" is specifically about Team '.
            'Owner, and a composed role holding team.members.manage is deliberately not '.
            'a substitute — PRD F1.3 protects the named role.',
    ],

    'Actions/Teams/ProvisionTeam.php' => [
        'count' => 2,
        'reason' => 'A provisioned team gets a Team Owner by name. There is no other '.
            'role it could be, and the audit entry records which it was.',
    ],

    'Http/Controllers/Admin/TeamController.php' => [
        'count' => 1,
        'reason' => 'The console invites a new team’s owner into the Team Owner role. '.
            'Its members *list* no longer names keys — that was the third copy of the '.
            'hard-coded list #142 removed.',
    ],

    'Models/Role.php' => [
        'count' => 1,
        'reason' => 'assignableWithinTeam() keeps Super Administrator off a team’s own '.
            'members screen. Naming it is the point: it is the one role a team may never '.
            'hand out, which is also why it is Platform surface.',
    ],

    'Http/Controllers/Settings/MemberController.php' => [
        'count' => 1,
        'reason' => 'The same refusal in the invite validator, so a hand-posted role_id '.
            'fails validation rather than relying on the picker. Its members *list* no '.
            'longer names keys — that was the first of the hard-coded lists #142 removed.',
    ],
];

/**
 * The role keys whose appearance in a query would be the #142 mistake.
 *
 * `status_viewer` and `contact` are here too: an exclusion list is the same
 * bug wearing a coat, and "everybody except the two client-ish roles" breaks
 * on the first composed role exactly as "the two team roles" does.
 */
function teamRoleKeyPattern(): string
{
    $keys = array_map(
        fn (SystemRole $role): string => preg_quote($role->value, '/'),
        SystemRole::cases(),
    );

    $cases = array_map(
        fn (SystemRole $role): string => preg_quote($role->name, '/'),
        SystemRole::cases(),
    );

    // The literal must be the *whole* string: 'team_member' is a role key and
    // 'team_membership_id' is a column, and a pattern that cannot tell them
    // apart flags every deal form in the codebase.
    return '/\'(?:'.implode('|', $keys).')\'|SystemRole::(?:'.implode('|', $cases).')\b/';
}

/**
 * Count real uses in a file, ignoring the ones written *about*.
 */
function teamRoleKeyUsesIn(string $contents): int
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return preg_match_all(teamRoleKeyPattern(), $code);
}

/**
 * A team, and a role composed inside it holding exactly these permissions.
 */
function membershipHolding(Team $team, string ...$permissionKeys): TeamMembership
{
    return app(TeamContext::class)->runFor($team, function () use ($team, $permissionKeys): TeamMembership {
        $role = Role::query()->create([
            'team_id' => $team->getKey(),
            'key' => 'composed_'.Str::lower(Str::random(8)),
            'name' => 'Composed '.Str::random(4),
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
        );

        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Composed',
            'last_name' => Str::random(6),
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach($role->getKey());

        return $membership->fresh(['roles.permissions']);
    });
}

it('records a team-access decision for every system role', function (): void {
    // The point of the file. A sixth role added to SystemRole without a line
    // in SYSTEM_ROLE_TEAM_ACCESS fails here rather than being discovered when
    // somebody cannot be revoked.
    $undecided = collect(SystemRole::cases())
        ->map(fn (SystemRole $role): string => $role->value)
        ->reject(fn (string $key): bool => array_key_exists($key, SYSTEM_ROLE_TEAM_ACCESS));

    expect($undecided->all())->toBe(
        [],
        'Every SystemRole needs an entry in SYSTEM_ROLE_TEAM_ACCESS saying whether it '.
        'puts somebody on the team, and why.',
    );
})->group('access-convention');

it('does not decide for a role that no longer exists', function (): void {
    $keys = array_map(fn (SystemRole $role): string => $role->value, SystemRole::cases());

    $stale = array_values(array_diff(array_keys(SYSTEM_ROLE_TEAM_ACCESS), $keys));

    expect($stale)->toBe([], 'Remove roles from SYSTEM_ROLE_TEAM_ACCESS once they are gone.');
});

it('seeds each system role to match its recorded decision', function (string $key, array $entry): void {
    // The decision above, checked against what the role actually holds. A
    // permission added to a role's list in Permissions::forSystemRoles() with
    // the wrong surface fails here — including the one this issue is about,
    // Status Viewer gaining status_page.view.
    $role = Role::query()->whereNull('team_id')->where('key', $key)->sole();

    $grantsAccess = Permissions::grantTeamAccess(
        $role->permissions->pluck('key')->all(),
    );

    expect($grantsAccess)->toBe(
        $entry['access'],
        "The {$key} role's seeded permissions disagree with SYSTEM_ROLE_TEAM_ACCESS. ".
        'Either the permission set changed surface, or the decision did. '.$entry['reason'],
    );
})->with(array_map(
    fn (string $key): array => [$key, SYSTEM_ROLE_TEAM_ACCESS[$key]],
    array_keys(SYSTEM_ROLE_TEAM_ACCESS),
));

it('gives every permission in the catalogue a surface', function (): void {
    // The other half. Roles inherit their answer from what they are made of,
    // so an unclassified permission is an undecided role by another route.
    $unclassified = collect(Permissions::catalogue())
        ->reject(fn (array $entry): bool => ($entry['surface'] ?? null) instanceof PermissionSurface)
        ->keys();

    expect($unclassified->all())->toBe(
        [],
        'Every permission needs a PermissionSurface: does holding it mean acting in the '.
        'team app, reading a client status page, or operating the platform?',
    );
});

it('keeps the team surface a real subset of the catalogue', function (): void {
    // A vacuous guard would be one where every permission is Team surface —
    // then `grantTeamAccess()` is "any permission at all" again, wearing a
    // new name, and nothing above would notice.
    expect(Permissions::onSurface(PermissionSurface::Client))->not->toBe([])
        ->and(Permissions::onSurface(PermissionSurface::Platform))->not->toBe([])
        ->and(count(Permissions::teamSurfaceKeys()))->toBeLessThan(count(Permissions::all()));
});

it('agrees between the model, the members screen, and the Team segment', function (): void {
    /*
     * The three callers the issue names, asked about the same five
     * memberships. Two of them used to answer with their own list of role
     * keys, and a composed role is precisely where those answers part company
     * with carriesAccess().
     */
    [$team, $owner] = $this->teamWithOwner();
    $this->withTeam($team);

    $composed = membershipHolding($team, Permissions::VIEW_DEALS);
    $clientSurface = membershipHolding($team, Permissions::VIEW_STATUS_PAGE);
    $nothing = membershipHolding($team);
    $platform = membershipHolding($team, Permissions::ADMINISTER_PLATFORM);

    $ownerMembership = $owner->membershipIn($team);
    expect($ownerMembership)->not->toBeNull();

    $expected = collect([$ownerMembership, $composed])->map->getKey()->sort()->values()->all();

    // 1. The model, row by row.
    expect($composed->carriesAccess())->toBeTrue()
        ->and($clientSurface->carriesAccess())->toBeFalse()
        ->and($nothing->carriesAccess())->toBeFalse()
        ->and($platform->carriesAccess())->toBeFalse();

    // 2. The scope, which is what the members screen and the console ask.
    expect(TeamMembership::query()->carryingAccess()->pluck('id')->sort()->values()->all())
        ->toBe($expected);

    // 3. The People index's Team segment.
    expect(app(PeopleDirectory::class)->query(PersonSegment::Team)->pluck('team_memberships.id')->sort()->values()->all())
        ->toBe($expected);

    // And the negative scope is the complement, so nobody lands on both tabs
    // or on neither.
    expect(TeamMembership::query()->notCarryingAccess()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$clientSurface, $nothing, $platform])->map->getKey()->sort()->values()->all());

    /*
     * 4. The Clients segment, which asks the same question inverted.
     *
     * All four of these are Active, so status alone does not separate them.
     * Under the old `['team_owner', 'team_member']` the composed role fell
     * through to Clients — filed under the customers, on the screen where
     * "remove" means remove.
     */
    $clients = app(PeopleDirectory::class)->query(PersonSegment::Clients)
        ->pluck('team_memberships.id')->sort()->values()->all();

    expect($clients)
        ->toBe(collect([$clientSurface, $nothing, $platform])->map->getKey()->sort()->values()->all())
        ->not->toContain($composed->getKey());
});

it('offers a composed role on the members screen it can be revoked from', function (): void {
    // The bug in one test. Somebody holding a team's own role can act in the
    // team; before #142 they were on neither list, and /settings/members is
    // the only screen that can revoke them.
    [$team, $owner] = $this->teamWithOwner();
    $this->enrollTwoFactor($owner);

    $composed = membershipHolding($team, Permissions::MANAGE_DEALS);

    $this->actingAsPerson($owner, $team);

    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'members',
            fn (mixed $members): bool => collect($members)->contains(
                fn (mixed $member): bool => data_get($member, 'id') === $composed->getKey(),
            ),
        ));
});

it('leaves a client-surface role off the members screen and in the directory', function (): void {
    // The other half of the same rule, and the one #110 would otherwise break.
    [$team, $owner] = $this->teamWithOwner();
    $this->enrollTwoFactor($owner);

    $client = membershipHolding($team, Permissions::VIEW_STATUS_PAGE);

    $this->actingAsPerson($owner, $team);

    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'members',
            fn (mixed $members): bool => ! collect($members)->contains(
                fn (mixed $member): bool => data_get($member, 'id') === $client->getKey(),
            ),
        ));

    expect(app(PeopleDirectory::class)->query(PersonSegment::Team)->pluck('team_memberships.id')->all())
        ->not->toContain($client->getKey());
});

it('keeps removing a client-surface person a directory edit, not a revocation', function (): void {
    /*
     * The sharpest consequence, and issue #142's real motivation.
     *
     * `PersonController::destroy()` branches on carriesAccess(): a membership
     * that carries access is revoked (needing `team.members.manage`), and one
     * that does not is removed from the directory (needing `people.manage`).
     * Heather is a Team Member — she holds the second and not the first — and
     * under "any permission at all" the day #110 gives Status Viewer
     * `status_page.view` is the day tidying up her directory starts returning
     * 403 with no explanation of what changed.
     */
    [$team, $member] = $this->teamWithMember();

    $client = membershipHolding($team, Permissions::VIEW_STATUS_PAGE);

    expect($member->membershipIn($team)?->hasPermission(Permissions::MANAGE_PEOPLE))->toBeTrue()
        ->and($member->membershipIn($team)?->hasPermission(Permissions::MANAGE_TEAM_MEMBERS))->toBeFalse();

    $this->actingAsPerson($member, $team);

    $this->delete("/people/{$client->getKey()}")->assertRedirect(route('people.index'));

    // Removed, not revoked. A revocation would have left the row live with a
    // `revoked_at`, which is PRD F1.3's shape and the wrong record of what
    // just happened.
    $row = TeamMembership::withTrashed()->whereKey($client->getKey())->sole();

    expect($row->trashed())->toBeTrue()
        ->and($row->revoked_at)->toBeNull();
});

it('offers the switcher the teams a person can act in, and no others', function (): void {
    /*
     * The fourth caller: Person::activeTeams(), which decides the team
     * switcher (S09) and what ResolveCurrentTeam will hand somebody. A client
     * surface role must not put a team in the list, or #110 gives every client
     * with a password a way into the agent's dashboard.
     */
    [$team] = $this->teamWithOwner();

    $composed = membershipHolding($team, Permissions::VIEW_DEALS);
    $client = membershipHolding($team, Permissions::VIEW_STATUS_PAGE);
    $contact = membershipHolding($team);

    expect($composed->person->activeTeams()->pluck('id')->all())->toBe([$team->getKey()])
        ->and($client->person->activeTeams()->all())->toBe([])
        ->and($contact->person->activeTeams()->all())->toBe([]);
});

it('shows the same team on the console as on the team’s own members screen', function (): void {
    /*
     * The third caller, which issue #142 did not name and which had the same
     * list. An operator asked to look into a team gets the members list that
     * team's owner sees — a console showing fewer people than the customer
     * does is worse than no console, because it looks authoritative.
     */
    [$team, $owner] = $this->teamWithOwner();

    $composed = membershipHolding($team, Permissions::MANAGE_DEALS);

    // Present so the equality below is an exclusion as well as an inclusion.
    membershipHolding($team, Permissions::VIEW_STATUS_PAGE);

    $this->actingAs($this->enrollTwoFactor(Person::factory()->superAdministrator()->create()));

    $this->get("/admin/teams/{$team->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'members',
            fn (mixed $members): bool => collect($members)->pluck('id')->sort()->values()->all()
                === collect([$owner->membershipIn($team), $composed])->map->getKey()->sort()->values()->all(),
        ));
});

it('has no hard-coded role-key test outside the sanctioned call sites', function (): void {
    $found = [];

    foreach ((new Finder)->files()->in([app_path()])->name('*.php') as $file) {
        $uses = teamRoleKeyUsesIn((string) file_get_contents($file->getRealPath()));

        if ($uses > 0) {
            $found[str_replace('\\', '/', $file->getRelativePathname())] = $uses;
        }
    }

    // The enum's own definition is not a use of it.
    unset($found['Enums/SystemRole.php']);

    $unsanctioned = array_diff_key($found, SANCTIONED_TEAM_ROLE_KEY_USES);

    expect($unsanctioned)->toBe(
        [],
        'A new file names a system role key. If it is deciding who can act in the team, '.
        'the answer is TeamMembership::carriesAccess() or its scopeCarryingAccess() — a '.
        'list of keys misses a team’s own composed roles (PRD F2.3), which is issue #142. '.
        'If it is genuinely about that named role — the last-owner rule, the 2FA mandate, '.
        'provisioning an owner — add it to SANCTIONED_TEAM_ROLE_KEY_USES with a reason.',
    );
});

it('counts the role-key uses in each sanctioned file', function (string $path, array $entry): void {
    $actual = teamRoleKeyUsesIn((string) file_get_contents(app_path($path)));

    expect($actual)->toBe(
        $entry['count'],
        "{$path} now names a system role key {$actual} times, not {$entry['count']}. ".
        'If the new one is legitimate, raise the count and widen the reason: '.$entry['reason'],
    );
})->with(array_map(
    fn (string $path): array => [$path, SANCTIONED_TEAM_ROLE_KEY_USES[$path]],
    array_keys(SANCTIONED_TEAM_ROLE_KEY_USES),
));

it('finds every sanctioned file still on disk', function (): void {
    foreach (array_keys(SANCTIONED_TEAM_ROLE_KEY_USES) as $path) {
        expect(file_exists(app_path($path)))->toBeTrue(
            "{$path} is listed as naming a role key and no longer exists. Remove the entry — ".
            'a stale allow-list reads as coverage it does not have.',
        );
    }
});
