<?php

declare(strict_types=1);

use App\Enums\DealDraftStep;
use App\Enums\DealSide;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\StageState;
use App\Models\Deal;
use App\Models\DealDraft;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Models\StageTemplate;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;

/**
 * S14 — create a deal (issue #74 · PRD §5.2).
 *
 * The definition of done has three clauses and each has a test named for it:
 * the five-step flow completes and produces an active first stage,
 * interrupting the wizard and returning resumes it, and a second workflow
 * attaches and both run concurrently (that one is in
 * `AttachWorkflowTest`).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

/** A deal type in the acting team. */
function typeOn(DealSide $side): DealType
{
    return app(TeamContext::class)->runFor(
        test()->team,
        fn (): DealType => DealType::factory()->create(['team_id' => test()->team->getKey(), 'side' => $side]),
    );
}

/** A template with named stages, so the walkthrough has something to attach. */
function templateWithStages(int $stages = 3): WorkflowTemplate
{
    $template = WorkflowTemplate::factory()->create(['team_id' => test()->team->getKey(), 'is_active' => true]);

    foreach (range(1, $stages) as $position) {
        StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => "Stage {$position}",
            'sort_order' => $position - 1,
        ]);
    }

    return $template;
}

/** Somebody in the directory. */
function clientIn(string $surname = 'Bosart'): TeamMembership
{
    return app(TeamContext::class)->runFor(test()->team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => test()->team->getKey(),
        'person_id' => Person::factory()->create()->getKey(),
        'first_name' => 'Emily',
        'last_name' => $surname,
        'status' => PersonLifecycleState::Active,
        'joined_at' => now(),
    ]));
}

it('walks PRD §5.2 end to end and activates the first stage', function (): void {
    /*
     * The definition of done's first clause, as the walkthrough states it:
     * Heather chooses Seller Representation, adds the client as a Seller,
     * adds the subject property, attaches the workflow, and the first stage
     * activates — which is not a step somebody takes.
     */
    $type = typeOn(DealSide::Sell);
    $client = clientIn('Bosart');
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '123 Main St']);
    $template = templateWithStages();

    $this->get('/deals/create')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Deals/Create')->where('draft.resumed', false));

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => $property->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => $template->getKey()])
        ->assertRedirect();

    $this->post('/deals/create')->assertRedirect();

    $deal = Deal::query()->sole();

    expect($deal->deal_type_id)->toBe($type->getKey())
        // Step 3: "Name auto-generates as 123 Main St."
        ->and($deal->generated_name)->toBe('123 Main St · Bosart Sale')
        // Step 2: the client, in the role the type implies.
        ->and($deal->participants()->sole()->participant_role)->toBe(ParticipantRole::Seller)
        ->and($deal->participants()->sole()->is_primary)->toBeTrue()
        // Step 3: the subject property.
        ->and(DealProperty::query()->sole()->is_subject)->toBeTrue()
        // Step 4 and 5: the workflow, and its first stage already running.
        ->and($deal->workflows()->count())->toBe(1);

    $workflow = $deal->workflows()->sole();

    expect($workflow->stages()->orderBy('sort_order')->first()->state)->toBe(StageState::Active)
        ->and($workflow->stages()->count())->toBe(3);
});

it('resumes a half-finished deal on the step it was left', function (): void {
    // The second clause, and the reason the draft is a row rather than
    // component state: Heather is doing this from a car.
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    // A new request, as a dropped connection and a reopened tab would be.
    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.step', DealDraftStep::Client->value)
            ->where('draft.dealTypeId', $type->getKey())
            ->where('draft.resumed', true));

    expect(Deal::query()->count())->toBe(0);
});

it('does not lose later answers when an earlier step is re-saved', function (): void {
    /*
     * Back is a button. A payload that took whatever arrived would let step
     * one erase steps two and three, and a dropped connection at that moment
     * would resume somebody to the beginning.
     */
    $type = typeOn(DealSide::Sell);
    $other = typeOn(DealSide::Sell);
    $client = clientIn();

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();

    // Back to step one, changing the type.
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $other->getKey()])->assertRedirect();

    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page
            ->where('draft.dealTypeId', $other->getKey())
            // Still there.
            ->where('draft.membershipId', $client->getKey())
            // And still on the furthest step reached, not back at step two.
            ->where('draft.step', DealDraftStep::Property->value));
});

it('creates a client inline, as a directory entry like any other', function (): void {
    // PRD §5.2 step 2: "from imported contacts or created inline."
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    $this->post('/deals/create/clients', [
        'first_name' => 'Emily',
        'last_name' => 'Bosart',
        'email' => 'emily@example.test',
    ])->assertRedirect();

    $membership = TeamMembership::query()->where('last_name', 'Bosart')->sole();

    expect($membership->status)->toBe(PersonLifecycleState::Lead);

    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page->where('draft.membershipId', $membership->getKey()));
});

it('refuses a client whose email is already in the directory', function (): void {
    // #60's lesson: a second, looser copy of the rules turns a duplicate
    // address into a 500. The wizard uses `/people`'s own.
    clientIn();
    TeamMembership::query()->where('last_name', 'Bosart')->update(['email' => 'taken@example.test']);

    $this->post('/deals/create/clients', [
        'first_name' => 'Someone',
        'email' => 'taken@example.test',
    ])->assertSessionHasErrors('email');
});

it('creates a property inline, through S37’s own rules', function (): void {
    $this->post('/deals/create/properties', [
        'street' => '1420 Pearl St',
        'city' => 'Boulder',
        // Lower case, so the state-code normalisation is exercised — the half
        // that would have been silently discarded by a trait conflict.
        'state_code' => 'co',
        'type' => 'single_family',
        'status' => 'pre_listing',
    ])->assertRedirect();

    expect(Property::query()->sole()->state_code)->toBe('CO');
});

it('creates a deal with no property, which a buyer’s deal has yet', function (): void {
    // IA §13.4: a buyer's deal is opened before there is a property to buy,
    // and that is the normal way round.
    $type = typeOn(DealSide::Buy);
    $client = clientIn('Nakamura');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();

    $this->post('/deals/create')->assertRedirect();

    $deal = Deal::query()->sole();

    expect(DealProperty::query()->count())->toBe(0)
        // Named after the client, which is what that fallback is for.
        ->and($deal->generated_name)->toBe('Nakamura Purchase');
});

it('creates a deal with no workflow, and S28 is how one arrives later', function (): void {
    // F4.7 allows several attached at different times, so the step is
    // skippable — a deal opened before a pack is installed is still a deal.
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->post('/deals/create')->assertRedirect();

    expect(Deal::query()->sole()->workflows()->count())->toBe(0);
});

it('keeps a typed name and still derives the other one', function (): void {
    // IA §10, and issue #59: two columns so a typed name survives every pass.
    $type = typeOn(DealSide::Sell);
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '123 Main St']);

    $this->patch('/deals/create', [
        'step' => 'type',
        'deal_type_id' => $type->getKey(),
        'name' => 'The Main St job',
    ])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => $property->getKey()])->assertRedirect();
    $this->post('/deals/create')->assertRedirect();

    $deal = Deal::query()->sole();

    expect($deal->name)->toBe('The Main St job')
        ->and($deal->generated_name)->toBe('123 Main St')
        ->and($deal->displayName())->toBe('The Main St job');
});

it('sends somebody back to step one when the deal type was archived meanwhile', function (): void {
    /*
     * A draft can sit in a pocket for a week. S76's archive dialog promises
     * no new deal can use an archived type, and `Deal` refuses one at save
     * time — a 500 at the last button of a wizard is the worst place to find
     * that out.
     */
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    $type->forceFill(['archived_at' => now()])->save();

    $this->post('/deals/create')->assertSessionHasErrors('deal_type_id');

    expect(Deal::query()->count())->toBe(0);

    // And the screen asks again rather than showing a type nobody can use.
    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page->where('draft.dealTypeId', null));
});

it('gives each person their own draft', function (): void {
    // The one exception to what `team_id` means here: resuming into a
    // colleague's half-typed address would lose work rather than share it.
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    [$otherTeam, $colleague] = $this->teamWithMember();
    unset($otherTeam);

    app(TeamContext::class)->runFor($this->team, function () use ($colleague): void {
        TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $colleague->getKey(),
            'first_name' => 'A',
            'last_name' => 'Colleague',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ])->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')
                ->where('key', App\Enums\SystemRole::TeamMember->value)->sole()->getKey(),
        );
    });

    $this->actingAsPerson($colleague, $this->team);

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.dealTypeId', null)->where('draft.resumed', false));

    expect(DealDraft::query()->count())->toBe(2);
});

it('abandons a draft without touching what it already created', function (): void {
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->post('/deals/create/clients', ['first_name' => 'Emily', 'last_name' => 'Bosart'])->assertRedirect();

    $this->delete('/deals/create')->assertRedirect('/deals');

    expect(DealDraft::query()->count())->toBe(0)
        ->and(DealDraft::withTrashed()->count())->toBe(1)
        // The person they added is theirs, and stays.
        ->and(TeamMembership::query()->where('last_name', 'Bosart')->exists())->toBeTrue();

    // And the slot is free straight away.
    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page->where('draft.resumed', false));
});
