<?php

declare(strict_types=1);

use App\Enums\DealDraftStep;
use App\Enums\DealSide;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\StageState;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealDraft;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\StageTemplate;
use App\Models\Team;
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

/** Somebody in the team holding no deal permissions at all (a Contact). */
function permissionlessDealMember(Team $team): Person
{
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'No',
            'last_name' => 'Permissions',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', SystemRole::Contact->value)->sole()->getKey(),
        );
    });

    return $person;
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
            Role::query()->whereNull('team_id')
                ->where('key', SystemRole::TeamMember->value)->sole()->getKey(),
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

/*
 * The four ways a client or a workflow was lost between the step that chose it
 * and the button that committed it. Each of these failed before the fix, and
 * each failed *silently* — a deal was created, so nothing looked wrong.
 */

it('refuses step two on a rental until the client’s role is chosen', function (): void {
    /*
     * `expectedRoles()` is empty on Rent and Other, so there is nothing to
     * fall back on. Nullable throughout meant the draft saved, the last button
     * succeeded, and the client was simply absent from the deal.
     */
    $type = typeOn(DealSide::Rent);
    $client = clientIn('Marsh');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])
        ->assertSessionHasErrors('participant_role');

    /*
     * And accepted with one, which is what makes the refusal above about the
     * missing role rather than about rentals.
     *
     * `Other`, because PRD §6.3's role list has no Tenant or Landlord and
     * `DocumentedVocabularyTest` binds the enum to that table. Whether a
     * rental needs its own roles is a product question for the PRD, not
     * something a wizard should settle by inventing a case.
     */
    $this->patch('/deals/create', [
        'step' => 'client',
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Other->value,
    ])->assertSessionHasNoErrors();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertRedirect();

    expect(Deal::query()->sole()->participants()->sole()->participant_role)
        ->toBe(ParticipantRole::Other);
});

it('carries the role through the inline-create client endpoint too', function (): void {
    /*
     * The second caller, written without the rule. This endpoint accepted no
     * `participant_role` at all, so on a rental it could not produce a
     * participant however the screen was used.
     */
    $type = typeOn(DealSide::Rent);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();

    /*
     * Asked of the **surname**, not the first name. `teamWithMember()` names
     * the acting member from Faker, whose first-name pool contains *Dana* —
     * so `where('first_name', 'Dana')->exists()` was occasionally true before
     * this request ever ran, and the test failed on a run that had proved
     * nothing about the endpoint. A fixture-name assertion has to name
     * something no factory can produce: *Okafor* is not in Faker's surname
     * pool, which is the same reason *Bosart* is used above.
     */
    $this->post('/deals/create/clients', ['first_name' => 'Dana', 'last_name' => 'Okafor'])
        ->assertSessionHasErrors('participant_role');

    expect(TeamMembership::query()->where('last_name', 'Okafor')->exists())
        // Authorization and validation both happen before anything is written,
        // so a refused request leaves no directory entry behind.
        ->toBeFalse();

    $this->post('/deals/create/clients', [
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'participant_role' => ParticipantRole::Other->value,
    ])->assertSessionHasNoErrors();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertRedirect();

    $participant = Deal::query()->sole()->participants()->sole();

    expect($participant->participant_role)->toBe(ParticipantRole::Other)
        ->and($participant->membership->fullName())->toBe('Dana Okafor');
});

it('does not add a client whose membership was revoked while the draft sat', function (): void {
    /*
     * The step's `exists` rule refuses a revoked membership and so does S25's
     * picker. The draft outlives both — it can sit for a month — so the commit
     * is the third place that has to ask.
     */
    $type = typeOn(DealSide::Sell);
    $client = clientIn('Vance');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $client->forceFill(['revoked_at' => now()])->save(),
    );

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();

    /*
     * Refused, not quietly created without them. The first version of this
     * asserted a deal with zero participants on the argument that S19 would
     * warn — and S19's warning filters `expectedRoles()`, which is empty on
     * Rent and Other, so on those two sides nothing would have said anything.
     */
    $this->post('/deals/create')->assertSessionHasErrors('team_membership_id');

    expect(Deal::query()->count())->toBe(0);
});

it('refuses the same way on a rental, where no warning downstream would catch it', function (): void {
    // The side that made the silent version wrong: `missingExpectedRoles()`
    // is empty here, so a deal created without the client says nothing at all.
    $type = typeOn(DealSide::Rent);
    $client = clientIn('Ilyas');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', [
        'step' => 'client',
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Other->value,
    ])->assertRedirect();

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $client->forceFill(['revoked_at' => now()])->save(),
    );

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertSessionHasErrors('team_membership_id');

    expect(Deal::query()->count())->toBe(0);
});

it('does not attach a template deactivated while the draft sat', function (): void {
    /*
     * Instantiating snapshots the whole tree and activates the first stage, so
     * attaching a template the team has withdrawn is the expensive half of the
     * mistake. Both other callers check `is_active`; this one did not.
     */
    $type = typeOn(DealSide::Sell);
    $template = templateWithStages();

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => $template->getKey()])
        ->assertRedirect();

    $template->forceFill(['is_active' => false])->save();

    /*
     * Refused, because the class docblock promises the last button "either
     * produces the whole thing or changes nothing" — and it calls a deal
     * whose workflow failed to attach *worse* than a half-made one, because
     * it looks finished.
     */
    $this->post('/deals/create')->assertSessionHasErrors('workflow_template_id');

    expect(Deal::query()->count())->toBe(0);
});

it('still creates a deal when no workflow was chosen at all', function (): void {
    // The refusal above is about a template that was chosen and withdrawn.
    // Choosing none stays legal: F4.7 attaches workflows at different times,
    // and S28 is the screen for the later ones.
    $type = typeOn(DealSide::Sell);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => null])->assertRedirect();

    $this->post('/deals/create')->assertSessionHasNoErrors();

    expect(Deal::query()->sole()->workflows()->count())->toBe(0);
});

it('does not create a draft in order to abandon one', function (): void {
    // `open()` creates when it finds nothing. Giving up on a wizard you never
    // started should leave the table exactly as it was.
    $this->delete('/deals/create')->assertRedirect('/deals');

    expect(DealDraft::withTrashed()->count())->toBe(0);
});

it('refuses the wizard to somebody with no deal permissions, and writes nothing', function (): void {
    /*
     * `POST /deals/create` is the one wizard write with no FormRequest in
     * front of it, so it was the one endpoint that resolved the draft — which
     * *creates* one — before asking the policy. A 403 that leaves a
     * `deal_drafts` row behind is a small leak, but it is the shape
     * `AuthorizationCoverageTest` cannot see: the source does call
     * `authorize()`, just too late.
     *
     * `destroy()` is here for the other half: with the check inside the
     * "is there a draft" branch, an unauthorized actor was answered with a
     * redirect rather than a refusal.
     */
    $outsider = permissionlessDealMember($this->team);

    $this->actingAsPerson($outsider, $this->team);

    $this->post('/deals/create')->assertForbidden();
    $this->delete('/deals/create')->assertForbidden();

    expect(DealDraft::withTrashed()->count())->toBe(0);
});

/*
 * Round 3: the two refusals round 2 added, and the three answers that step one
 * invalidates. Every one of these is a case where the screen and the draft
 * disagreed about what the deal would be.
 */

it('clears the chosen role when the deal type changes under it', function (): void {
    /*
     * `clientRole()` prefers a chosen role over the implied one, which is
     * right — a rental has no implied one to fall back on. But the choice then
     * outlived the type it was made for: switch Rental to Sale and the screen
     * said "they'll be added as the Seller" while the draft still held
     * `other`, so the deal was created with a role nothing on screen had named
     * and S19 warned about a missing Seller immediately.
     */
    $rental = typeOn(DealSide::Rent);
    $sale = typeOn(DealSide::Sell);
    $client = clientIn('Adeyemi');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $rental->getKey()])->assertRedirect();
    $this->patch('/deals/create', [
        'step' => 'client',
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Other->value,
    ])->assertRedirect();

    // Back to step one, and a different type.
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $sale->getKey()])->assertRedirect();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertRedirect();

    // The Sale's implied role, not the Rental's leftover.
    expect(Deal::query()->sole()->participants()->sole()->participant_role)
        ->toBe(ParticipantRole::Seller);
});

it('clears a chosen template the new deal type cannot start from', function (): void {
    /*
     * The genuine mismatch. The first version of this test never associated
     * the template with either type, so `templatesFor()` took the "no
     * associations, offer everything" branch and *both* types offered it — it
     * pinned the shared-template case and asserted that dropping the answer
     * was correct, which is the opposite of what the next test says.
     */
    $first = typeOn(DealSide::Sell);
    $second = typeOn(DealSide::Buy);
    $template = templateWithStages();

    /*
     * Both types need associations for this to be a mismatch at all. A type
     * with none offers *everything* — that is `templatesFor()`'s deliberate
     * "a team that has not wired its templates up should not meet an empty
     * picker" branch — so attaching only to the first would leave the second
     * offering it anyway, and the test would be asserting the opposite case
     * under a name that says otherwise.
     */
    $template->dealTypes()->attach($first->getKey());
    templateWithStages()->dealTypes()->attach($second->getKey());

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $first->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => $template->getKey()])
        ->assertRedirect();

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $second->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();

    $this->post('/deals/create')->assertSessionHasNoErrors();

    // No workflow, rather than one the new type was never offered. S28
    // attaches one to the live deal, which is the recovery.
    expect(Deal::query()->sole()->workflows()->count())->toBe(0);
});

it('keeps a chosen template the new deal type still offers', function (): void {
    /*
     * The case where clearing is least necessary and most harmful. The answer
     * was never invalid — the picker goes on offering exactly this template —
     * so dropping it is pure loss, and the row would still have rendered as
     * chosen over a draft that no longer held it.
     */
    $first = typeOn(DealSide::Sell);
    $second = typeOn(DealSide::Buy);
    $template = templateWithStages();

    $template->dealTypes()->attach([$first->getKey(), $second->getKey()]);

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $first->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => $template->getKey()])
        ->assertRedirect();

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $second->getKey()])->assertRedirect();

    // Still chosen, and still shown as chosen.
    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page->where('draft.workflowTemplateId', $template->getKey()));

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertSessionHasNoErrors();

    expect(Deal::query()->sole()->workflows()->count())->toBe(1);
});

it('keeps a re-saved deal type from clearing answers it did not invalidate', function (): void {
    // The invalidation is keyed on the type *changing*. Re-saving step one
    // with the same answer — which the Back button does — must not throw away
    // step two.
    $type = typeOn(DealSide::Rent);
    $client = clientIn('Nakamura');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', [
        'step' => 'client',
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Other->value,
    ])->assertRedirect();

    $this->patch('/deals/create', [
        'step' => 'type',
        'deal_type_id' => $type->getKey(),
        'name' => 'Renamed',
    ])->assertRedirect();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertSessionHasNoErrors();

    expect(Deal::query()->sole()->participants()->sole()->participant_role)
        ->toBe(ParticipantRole::Other);
});

it('does not show a revoked client as the chosen one on the recovery screen', function (): void {
    /*
     * The screen that has just refused the draft is the worst place to show a
     * green "chosen" badge for the person it refused. Two of three callers
     * filtered revoked memberships; this one did not, and it is the one that
     * draws the page.
     */
    $type = typeOn(DealSide::Sell);
    $client = clientIn('Petrova');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $client->forceFill(['revoked_at' => now()])->save(),
    );

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('chosen.membership', null));
});

it('clears a role answered before any deal type was chosen', function (): void {
    /*
     * The half of the type-change rule that `$previous === null` left open.
     * "No type chosen yet" is only "nothing to invalidate" if a later answer
     * cannot exist yet — and it can: the step nav is unguarded and the role
     * select shows whenever nothing implies a role, which includes a draft
     * with no type at all.
     */
    $sale = typeOn(DealSide::Sell);
    $client = clientIn('Okonkwo');

    $this->patch('/deals/create', [
        'step' => 'client',
        'team_membership_id' => $client->getKey(),
        'participant_role' => ParticipantRole::Other->value,
    ])->assertSessionHasNoErrors();

    // Step one, for the first time.
    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $sale->getKey()])
        ->assertSessionHasNoErrors();

    $this->patch('/deals/create', ['step' => 'property', 'property_id' => null])->assertRedirect();
    $this->post('/deals/create')->assertSessionHasNoErrors();

    // The Sale's implied Seller, not the role answered before there was a
    // deal type to imply anything.
    expect(Deal::query()->sole()->participants()->sole()->participant_role)
        ->toBe(ParticipantRole::Seller);
});

it('reports a cleared template to the screen, not just to the payload', function (): void {
    /*
     * The server forgetting an answer is only half a fix — the screen has to
     * be told, or it keeps drawing the template as chosen over a draft that no
     * longer holds it, and Create produces a deal with no workflow and a
     * success toast.
     *
     * Asserted on the prop the component mirrors, because that is the channel
     * that was silent.
     */
    $first = typeOn(DealSide::Sell);
    $second = typeOn(DealSide::Buy);
    $template = templateWithStages();

    // Both types associated, so the second genuinely excludes this template —
    // a type with no associations offers everything, which would make the
    // switch a no-op. The shared-template case is the test above.
    $template->dealTypes()->attach($first->getKey());
    templateWithStages()->dealTypes()->attach($second->getKey());

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $first->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'template', 'workflow_template_id' => $template->getKey()])
        ->assertRedirect();

    $this->get('/deals/create')
        ->assertInertia(fn ($page) => $page->where('draft.workflowTemplateId', $template->getKey()));

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $second->getKey()])->assertRedirect();

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.workflowTemplateId', null)
            ->where('draft.participantRole', null));
});

it('does not report a revoked client through the id the screen posts back', function (): void {
    // `draft.membershipId` is the fourth reader of this field and the role
    // setter PATCHes it, so an unfiltered one would post an id the step's own
    // `exists` rule refuses — a validation error on a field nobody touched.
    $type = typeOn(DealSide::Sell);
    $client = clientIn('Sandoval');

    $this->patch('/deals/create', ['step' => 'type', 'deal_type_id' => $type->getKey()])->assertRedirect();
    $this->patch('/deals/create', ['step' => 'client', 'team_membership_id' => $client->getKey()])->assertRedirect();

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $client->forceFill(['revoked_at' => now()])->save(),
    );

    $this->get('/deals/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.membershipId', null)
            ->where('chosen.membership', null));
});
