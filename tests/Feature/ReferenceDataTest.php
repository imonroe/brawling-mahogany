<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\DealType;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;

/**
 * Reference data is part of the schema in everything but name.
 *
 * `ReferenceDataSeeder` has always said *"every environment has them,
 * including production"*. Nothing made that true: the deploy ran
 * `migrate --force` and stopped, so a real deployment had an empty
 * `permissions` table and no system roles — and an application in that state
 * is inert rather than broken-looking. Provisioning a team looks up the
 * `team_owner` role and finds nothing; every policy denies because there is no
 * permission to grant.
 *
 * The deploy now seeds. This is the test that keeps the sentence honest.
 */
it('seeds the whole permission catalogue', function (): void {
    // The catalogue is defined in code (PRD §6.2) and the table is a copy of
    // it. A key in one and not the other is a permission nothing can grant.
    expect(Permission::query()->pluck('key')->sort()->values()->all())
        ->toBe(collect(Permissions::catalogue())->keys()->sort()->values()->all());
});

it('seeds the five system roles PRD F2.2 fixes', function (): void {
    $seeded = Role::query()->whereNull('team_id')->pluck('key')->sort()->values()->all();

    expect($seeded)->toBe(
        collect(SystemRole::cases())->map(fn (SystemRole $role): string => $role->value)->sort()->values()->all(),
    );
});

it('seeds the three deal types and no more', function (): void {
    // PRD §2.2: commercial is deferred to a pack, and ongoing rental
    // management is out permanently for a licensing reason.
    expect(DealType::query()->whereNull('team_id')->pluck('name')->sort()->values()->all())
        ->toBe(['Buyer Representation', 'Rental Placement', 'Seller Representation']);
});

/**
 * The property that lets it run on every deploy.
 *
 * `updateOrCreate` rather than `create`, so a changed permission description
 * ships with the code that changed it and a second run inserts nothing.
 */
it('changes nothing when it runs again', function (): void {
    $before = [
        'permissions' => Permission::query()->count(),
        'roles' => Role::query()->whereNull('team_id')->count(),
        'dealTypes' => DealType::query()->whereNull('team_id')->count(),
    ];

    $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])->assertSuccessful();

    expect([
        'permissions' => Permission::query()->count(),
        'roles' => Role::query()->whereNull('team_id')->count(),
        'dealTypes' => DealType::query()->whereNull('team_id')->count(),
    ])->toBe($before);
});

it('gives every system role its permissions', function (): void {
    // A role with an empty permission set is a role that grants nothing, which
    // is correct for Status Viewer and Contact and a bug for the other three.
    foreach ([SystemRole::TeamOwner, SystemRole::TeamMember, SystemRole::SuperAdministrator] as $role) {
        $model = Role::query()->whereNull('team_id')->where('key', $role->value)->sole();

        expect($model->permissions()->count())
            ->toBeGreaterThan(0, "The {$role->value} role was seeded with no permissions.");
    }
});
