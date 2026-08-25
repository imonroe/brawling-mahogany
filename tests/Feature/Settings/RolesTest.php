<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\TeamMembership;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;

/**
 * S75 — roles and permissions (PRD §4.2 F2.3 · IA §7 · issue #88).
 *
 * Two properties carry this screen, and neither is CRUD.
 *
 * **A system role is the product's, not the customer's.** F2.3 is that a team
 * differs by composing a new role, so the five shipped ones are uneditable all
 * the way down — a team that renamed Team Member would change what that word
 * means to everybody reading their own audit log six months later.
 *
 * **The permission list a team may compose from is the team surface only.**
 * The platform console's permissions are not a customer's to grant themselves,
 * and the refusal is a validation error rather than a quiet filter: a request
 * naming `platform.teams.manage` is a request nobody's screen rendered.
 */
beforeEach(function (): void {
    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);
});

it('shows the shipped roles and the team’s own, with the catalogue to compose from', function (): void {
    $this->get('/settings/roles')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Roles')
            // The five shipped roles, system first.
            ->where('roles.0.isSystem', true)
            ->has('catalogue')
            ->where('can.manage', true));
});

it('offers a team none of the platform console’s permissions', function (): void {
    $response = $this->get('/settings/roles')->assertOk();

    $offered = collect($response->viewData('page')['props']['catalogue'])->pluck('key')->all();

    /*
     * The assertion is stated against the catalogue rather than against a
     * hand-written list, so a permission added to the platform surface in a
     * later slice is covered by the test that exists.
     */
    expect($offered)->toBe(Permissions::teamSurfaceKeys())
        ->and($offered)->not->toContain(Permissions::ADMINISTER_PLATFORM);
});

it('composes a role from the permissions a team may grant', function (): void {
    $this->post('/settings/roles', [
        'name' => 'Listing Coordinator',
        'description' => 'Runs the listing side and nothing else.',
        'permissions' => [Permissions::MANAGE_DEALS, Permissions::VIEW_DEALS],
    ])->assertRedirect();

    $role = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Role => Role::query()->where('name', 'Listing Coordinator')->sole(),
    );

    expect($role->team_id)->toBe($this->team->getKey())
        ->and($role->is_system)->toBeFalse()
        // Derived, never typed — the key is what every permission check is
        // written against, and a customer choosing `team_owner` would be a
        // customer choosing what a name means in this product.
        ->and($role->key)->toBe('listing_coordinator')
        ->and($role->permissionKeys())->toEqualCanonicalizing([
            Permissions::MANAGE_DEALS,
            Permissions::VIEW_DEALS,
        ]);
});

it('refuses a permission outside the team surface rather than dropping it', function (): void {
    $this->post('/settings/roles', [
        'name' => 'Quietly Enormous',
        'permissions' => [Permissions::MANAGE_DEALS, Permissions::ADMINISTER_PLATFORM],
    ])->assertSessionHasErrors('permissions.1');

    expect(Role::withoutGlobalScopes()->where('name', 'Quietly Enormous')->exists())->toBeFalse();
});

it('refuses to edit a shipped role, all the way down', function (): void {
    $system = Role::query()->whereNull('team_id')->where('key', 'team_member')->sole();

    $this->patch("/settings/roles/{$system->getKey()}", [
        'name' => 'Team Member (ours)',
        'permissions' => [Permissions::MANAGE_DEALS],
    ])->assertForbidden();

    $this->delete("/settings/roles/{$system->getKey()}/archive")->assertForbidden();

    expect($system->refresh()->name)->toBe('Team Member');
});

it('counts the holders before the choice, and archives reversibly', function (): void {
    $role = app(TeamContext::class)->runFor($this->team, function (): Role {
        /*
         * `forceFill`, because `key`, `team_id` and `is_system` are no longer
         * fillable — they are derived by the controller, and a request body
         * choosing any of the three is the hole the counterfeit-owner test
         * below is about.
         */
        $role = new Role;
        $role->forceFill([
            'team_id' => $this->team->getKey(),
            'key' => 'listing_coordinator',
            'name' => 'Listing Coordinator',
            'is_system' => false,
        ])->save();

        TeamMembership::query()->where('team_id', $this->team->getKey())->sole()
            ->roles()->attach($role->getKey());

        return $role;
    });

    $this->get('/settings/roles')
        ->assertOk()
        // Shown *before* the choice: archiving a role held by somebody takes
        // that person's access with it, which is a sentence to read first.
        ->assertInertia(fn ($page) => $page->where(
            'roles',
            fn ($roles) => collect($roles)->firstWhere('id', $role->getKey())['holders'] === 1,
        ));

    $this->delete("/settings/roles/{$role->getKey()}/archive")->assertRedirect();

    expect(Role::withTrashed()->find($role->getKey())?->trashed())->toBeTrue();

    $this->post("/settings/roles/{$role->getKey()}/restore")->assertRedirect();

    expect(Role::withTrashed()->find($role->getKey())?->trashed())->toBeFalse();
});

it('has no destroy route at all', function (): void {
    /*
     * The rule Frontend conventions §4 records for deal types, held here
     * rather than remembered: a role appears in every audit entry and every
     * membership that ever held it, so a hard delete makes those unreadable.
     */
    $destroys = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_starts_with((string) $route->uri(), 'settings/roles'))
        ->map(fn ($route): string => (string) $route->uri());

    expect($destroys->values()->all())->toBe(['settings/roles/{role}/archive']);
});

it('shows another team none of this one’s roles', function (): void {
    app(TeamContext::class)->runFor($this->team, function (): void {
        $role = new Role;
        $role->forceFill([
            'team_id' => $this->team->getKey(),
            'key' => 'ours',
            'name' => 'Ours Alone',
            'is_system' => false,
        ])->save();
    });

    [$otherTeam, $otherOwner] = $this->teamWithOwner();

    $this->enrollTwoFactor($otherOwner);
    $this->actingAsPerson($otherOwner, $otherTeam);

    $response = $this->get('/settings/roles')->assertOk();

    $names = collect($response->viewData('page')['props']['roles'])->pluck('name');

    expect($names)->not->toContain('Ours Alone');
});

it('refuses a role named after one the product ships', function (): void {
    /*
     * `Str::slug('Team Owner', '_')` is exactly `team_owner`, and the unique
     * index is over `(team_id, key)` while the shipped rows have no team — so
     * the database permitted the row, and every check written as
     * `roles.key = 'team_owner'` then treated the counterfeit as the real
     * thing. The sharpest consequence is the one asserted below it.
     */
    $this->post('/settings/roles', [
        'name' => 'Team Owner',
        'permissions' => [Permissions::VIEW_DEALS],
    ])->assertSessionHasErrors('name');

    expect(Role::withTrashed()->where('key', 'team_owner')->count())->toBe(1);
});

it('does not count a counterfeit owner as an owner', function (): void {
    /*
     * The half that holds however the row got there. Somebody who has one by
     * another route — a seed, a fixture, a future import — must not be able to
     * stand in for the last real owner, because `RevokeMembership` counts
     * "other owners" before letting one go and a team with none is locked out
     * of its own settings.
     */
    $counterfeit = app(TeamContext::class)->runFor($this->team, function (): Role {
        $role = new Role;
        $role->forceFill([
            'team_id' => $this->team->getKey(),
            'key' => 'team_owner',
            'name' => 'Team Owner',
            'is_system' => false,
        ])->save();

        return $role;
    });

    $membership = app(TeamContext::class)->runFor($this->team, function () use ($counterfeit): TeamMembership {
        $one = TeamMembership::query()
            ->where('person_id', '!=', $this->owner->getKey())
            ->firstOr(fn (): TeamMembership => TeamMembership::query()->create([
                'team_id' => $this->team->getKey(),
                'person_id' => App\Models\Person::factory()->create()->getKey(),
                'first_name' => 'Nadia',
                'last_name' => 'Okafor',
                'joined_at' => now(),
            ]));

        $one->roles()->attach($counterfeit->getKey());

        return $one;
    });

    expect($membership->fresh()->hasRole('team_owner'))->toBeFalse();

    // And the real owner is still refused, which is the point: the guard has
    // to see exactly one owner here, not two.
    $owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->where('person_id', $this->owner->getKey())->sole());

    expect($owner->hasRole('team_owner'))->toBeTrue();
});

it('refuses a second role with the same name in one team', function (): void {
    $this->post('/settings/roles', ['name' => 'Listing Coordinator'])->assertRedirect();

    // Before this, the derived key hit the unique index and answered with a
    // 500 rather than a sentence.
    $this->post('/settings/roles', ['name' => 'Listing Coordinator'])
        ->assertSessionHasErrors('name');

    expect(app(TeamContext::class)->runFor(
        $this->team,
        fn (): int => Role::query()->where('key', 'listing_coordinator')->count(),
    ))->toBe(1);
});
