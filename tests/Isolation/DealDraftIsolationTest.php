<?php

declare(strict_types=1);

use App\Enums\DealDraftStep;
use App\Enums\DealSide;
use App\Models\DealDraft;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Support\Tenancy\TeamContext;

/**
 * The tenant boundary around S14 (issue #74 · ADR 0002).
 *
 * The wizard resolves the draft from the **actor**, never from an id in a
 * URL, so the usual "404 another team's row" shape does not apply — there is
 * no id to send. What can be sent is a foreign id inside a *step*, which is
 * the vector this file enumerates.
 *
 * Every refusal is paired with the same actor succeeding on their own row, so
 * a 422 cannot pass for want of a permission.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

function typeIn(App\Models\Team $team): DealType
{
    return app(TeamContext::class)->runFor(
        $team,
        fn (): DealType => DealType::factory()->create(['team_id' => $team->getKey(), 'side' => DealSide::Sell]),
    );
}

it('refuses another team’s deal type in step one', function (): void {
    $foreign = typeIn($this->teamB);
    $own = typeIn($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own type is accepted.
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $own->getKey()])
        ->assertSessionHasNoErrors();

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $foreign->getKey()])
        ->assertSessionHasErrors('deal_type_id');
});

it('refuses another team’s property in step three', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn (): Property => Property::factory()->create(['team_id' => $this->teamB->getKey()]),
    );
    $own = app(TeamContext::class)->runFor(
        $this->teamA,
        fn (): Property => Property::factory()->create(['team_id' => $this->teamA->getKey()]),
    );

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => $own->getKey()])
        ->assertSessionHasNoErrors();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => $foreign->getKey()])
        ->assertSessionHasErrors('property_id');
});

it('never resumes into another team’s draft', function (): void {
    /*
     * The same person can be in two teams. A draft is keyed on the team *and*
     * the person, so switching teams mid-wizard has to start a fresh one
     * rather than showing the other team's half-typed deal.
     */
    $person = Person::factory()->create();

    foreach ([$this->teamA, $this->teamB] as $team) {
        app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
            App\Models\TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => $person->getKey(),
                'first_name' => 'Both',
                'last_name' => 'Teams',
                'status' => App\Enums\PersonLifecycleState::Active,
                'joined_at' => now(),
            ])->roles()->attach(
                App\Models\Role::query()->whereNull('team_id')
                    ->where('key', App\Enums\SystemRole::TeamMember->value)->sole()->getKey(),
            );
        });
    }

    $typeA = typeIn($this->teamA);

    $this->actingAsPerson($person, $this->teamA);
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $typeA->getKey()])->assertRedirect();

    // Same person, other team.
    $this->actingAsPerson($person, $this->teamB);

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.dealTypeId', null)->where('draft.resumed', false));

    expect(DealDraft::withoutTeamScope()->count())->toBe(2);
});

it('offers only this team’s templates and deal types', function (): void {
    app(TeamContext::class)->runFor($this->teamB, fn () => App\Models\WorkflowTemplate::factory()
        ->create(['team_id' => $this->teamB->getKey(), 'name' => 'B Private', 'is_active' => true]));

    $own = typeIn($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $own->getKey()])->assertRedirect();

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(function ($page) use ($own): void {
            $types = collect($page->toArray()['props']['dealTypes'])->pluck('id');
            $templates = collect($page->toArray()['props']['templates'])->pluck('name');

            expect($types)->toContain($own->getKey())
                ->and($templates)->not->toContain('B Private');
        });
});

it('purges a draft nobody came back to', function (): void {
    /*
     * `purgeRowsFor()` sweeps by `deleted_at` and reaches nothing here: a
     * draft abandoned by walking away was never deleted. A staging table
     * needs its own sweep, because the thing that ends its life is neglect
     * rather than an action — the shape #61 shipped and had found for it.
     */
    $own = typeIn($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $own->getKey()])->assertRedirect();

    expect(DealDraft::query()->count())->toBe(1);

    // Untouched for a month.
    Illuminate\Support\Facades\DB::table('deal_drafts')->update(['updated_at' => now()->subDays(60)]);

    $this->artisan('records:purge')->assertSuccessful();

    expect(DealDraft::withTrashed()->count())->toBe(0);
});

it('leaves a draft somebody is still working on', function (): void {
    // The control for the sweep above: `updated_at`, not `created_at`, so a
    // draft started in March and touched yesterday survives.
    $own = typeIn($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $own->getKey()])->assertRedirect();

    Illuminate\Support\Facades\DB::table('deal_drafts')->update([
        'created_at' => now()->subDays(90),
        'updated_at' => now()->subDay(),
    ]);

    $this->artisan('records:purge')->assertSuccessful();

    expect(DealDraft::query()->count())->toBe(1)
        ->and(DealDraft::query()->sole()->step)->toBe(DealDraftStep::Client);
});
