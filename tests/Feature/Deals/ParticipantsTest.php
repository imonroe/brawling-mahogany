<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\ParticipantRole;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealType;
use App\Models\TeamMembership;
use App\Support\Deals\DealRoster;

/**
 * S19 and S25 — deal participants (issue #60 · PRD §4.3 F3.3, §7.2).
 *
 * The definition of done is three things, and each gets its own tests: a
 * person holds different roles on different deals at once; the tab groups by
 * role and names the roles that are absent; and adding somebody already on the
 * deal warns rather than duplicating.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->seed(Database\Seeders\DealTypeSeeder::class);

    $this->sellSide = DealType::query()->whereNull('team_id')
        ->where('name', 'Seller Representation')->sole();

    $this->deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $this->sellSide->getKey(),
        'name' => '123 Main St',
    ]);
});

/** A membership in this team, which is what a participant points at (#140). */
function directoryEntry(string $first = 'Claire', string $last = 'Nakamura'): TeamMembership
{
    return TeamMembership::query()->create([
        'team_id' => test()->team->getKey(),
        'person_id' => App\Models\Person::factory()->create()->getKey(),
        'first_name' => $first,
        'last_name' => $last,
        'email' => mb_strtolower($first).'@example.test',
        'status' => App\Enums\PersonLifecycleState::Active,
    ]);
}

/**
 * PRD §7.2's whole point, and the reason this table exists at all.
 */
it('lets one person hold different roles on different deals at once', function (): void {
    $claire = directoryEntry();

    $second = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')
            ->where('name', 'Buyer Representation')->sole()->getKey(),
    ]);

    $roster = app(DealRoster::class);

    $roster->add($this->deal, $claire, ParticipantRole::Seller);
    $roster->add($second, $claire, ParticipantRole::Buyer);

    // `pluck` returns the cast column, so these are enum instances.
    expect(DealParticipant::query()->where('team_membership_id', $claire->getKey())
        ->pluck('participant_role')
        ->map(fn (ParticipantRole $role): string => $role->value)
        ->all())
        ->toEqualCanonicalizing(['seller', 'buyer']);
});

it('groups the tab by role, in the order the PRD lists them', function (): void {
    $roster = app(DealRoster::class);

    // Added inspector-first, deliberately: a deal where the inspector was
    // booked before the listing agreement was signed must not render
    // Inspector above Seller.
    $roster->add($this->deal, directoryEntry('Lee'), ParticipantRole::Inspector);
    $roster->add($this->deal, directoryEntry('Claire'), ParticipantRole::Seller);
    $roster->add($this->deal, directoryEntry('Sam'), ParticipantRole::Seller);

    $this->get("/deals/{$this->deal->getKey()}/people")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/People')
            ->has('roles', 2)
            ->where('roles.0.label', 'Seller')
            ->has('roles.0.people', 2)
            ->where('roles.1.label', 'Inspector'));
});

/**
 * The state that earns the screen. Named, not counted.
 */
it('names the expected role a sell-side deal is missing', function (): void {
    $this->get("/deals/{$this->deal->getKey()}/people")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('missingRoles', 1)
            ->where('missingRoles.0.label', 'Seller'));

    app(DealRoster::class)->add($this->deal, directoryEntry(), ParticipantRole::Seller);

    $this->get("/deals/{$this->deal->getKey()}/people")
        ->assertInertia(fn ($page) => $page->has('missingRoles', 0));
});

it('expects a Buyer on a buy-side deal', function (): void {
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')
            ->where('name', 'Buyer Representation')->sole()->getKey(),
    ]);

    expect(app(DealRoster::class)->missingExpectedRoles($deal))
        ->toBe([ParticipantRole::Buyer]);
});

it('expects nothing of a rental placement, rather than guessing', function (): void {
    // PRD §6.3 has no Tenant or Landlord role, so any expectation here would
    // be invented — and a screen asserting a wrong requirement is worse than
    // one asserting none.
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')
            ->where('name', 'Rental Placement')->sole()->getKey(),
    ]);

    expect(app(DealRoster::class)->missingExpectedRoles($deal))->toBe([]);
});

it('adds somebody the team already knows', function (): void {
    $claire = directoryEntry();

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'team_membership_id' => $claire->getKey(),
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertRedirect("/deals/{$this->deal->getKey()}/people")
        ->assertSessionHasNoErrors();

    expect(DealParticipant::query()->sole()->team_membership_id)->toBe($claire->getKey());
});

it('creates somebody inline without leaving the deal', function (): void {
    // PRD §5.2: "from imported contacts or created inline". The inline path
    // goes through the same action /people uses, so the person is a directory
    // entry like any other rather than a lesser row from a second code path.
    $this->post("/deals/{$this->deal->getKey()}/people", [
        'first_name' => 'Sam',
        'last_name' => 'Ortiz',
        'email' => 'sam@example.test',
        'participant_role' => ParticipantRole::Buyer->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $membership = TeamMembership::query()->whereRaw('lower(email) = ?', ['sam@example.test'])->sole();

    expect($membership->fullName())->toBe('Sam Ortiz')
        ->and(DealParticipant::query()->sole()->team_membership_id)->toBe($membership->getKey());
});

it('needs either an existing person or a new one', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/people", [
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertSessionHasErrors(['team_membership_id', 'first_name']);

    expect(DealParticipant::query()->count())->toBe(0);
});

/**
 * The duplicate rule, both halves.
 */
/**
 * A sentence, not a stack trace.
 *
 * The first version of this test asserted
 * `toThrow(UniqueConstraintViolationException::class)` at the service level,
 * which framed an unhandled 500 as the intended behaviour and is why a green
 * suite agreed with one. The database refusing it is the backstop; the answer
 * a person gets is a validation error.
 */
it('refuses the same person in the same role twice, through the route', function (): void {
    $claire = directoryEntry();

    app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller);

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'team_membership_id' => $claire->getKey(),
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertRedirect()->assertSessionHasErrors('participant_role');

    expect(DealParticipant::query()->count())->toBe(1);
});

it('refuses it in the service too, where the rule cannot reach', function (): void {
    // The window between the rule's select and the insert is real — the
    // candidate list is fetched on a debounce, so two people on one deal race
    // here. The catch inside `add()` is what makes that a sentence as well.
    $claire = directoryEntry();

    app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller);

    expect(fn () => app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

/**
 * The defect this repo has now shipped three times: a partial unique index
 * with no validation rule in front of it.
 */
it('refuses an inline person whose address is already in the directory', function (): void {
    directoryEntry('Sam');

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'first_name' => 'Samuel',
        'email' => 'sam@example.test',
        'participant_role' => ParticipantRole::Buyer->value,
    ])->assertSessionHasErrors('email');

    expect(DealParticipant::query()->count())->toBe(0)
        ->and(TeamMembership::query()->whereRaw('lower(email) = ?', ['sam@example.test'])->count())->toBe(1);
});

it('folds the address before comparing it, like the index does', function (): void {
    // `PersonRules::prepareForValidation()` comes with the trait. Without the
    // fold, `SAM@example.test` walks past a rule comparing against
    // `lower(email)` and lands on the index as a 500.
    directoryEntry('Sam');

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'first_name' => 'Samuel',
        'email' => 'SAM@Example.TEST',
        'participant_role' => ParticipantRole::Buyer->value,
    ])->assertSessionHasErrors('email');
});

/**
 * The regression the first version of the rule introduced.
 *
 * The rule asked *"was a name typed"*; the controller asks *"was a membership
 * picked"*. The modal's "Back to search" leaves `first_name` and `email`
 * behind, so picking an existing person was refused for an address that was
 * never going to be written — and the error rendered only in create mode, so
 * the button appeared to do nothing.
 *
 * The path in is the one the rule's own message invites: told the address is
 * already in the directory, the obvious move is to go back and pick the person
 * who has it.
 */
it('does not refuse an existing person for fields the create branch left behind', function (): void {
    $claire = directoryEntry();

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'team_membership_id' => $claire->getKey(),
        // Left over from an abandoned create, exactly as the modal sent it.
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DealParticipant::query()->sole()->team_membership_id)->toBe($claire->getKey());
});

it('frees the pairing again through the route, not just the service', function (): void {
    /*
     * The rule's `whereNull('deleted_at')` is what makes this work, and it was
     * only ever exercised by a service-level test that never touches the
     * FormRequest — so the predicate the commit message calls load-bearing was
     * untested where it actually runs.
     */
    $claire = directoryEntry();

    $participant = app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller);

    app(DealRoster::class)->remove($participant);

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'team_membership_id' => $claire->getKey(),
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DealParticipant::query()->count())->toBe(1);
});

it('still lets an existing person be picked while the rule is on', function (): void {
    // The control: the uniqueness rule applies to the create-inline branch
    // only, so picking somebody from the directory is not refused for having
    // the address they already have.
    $claire = directoryEntry();

    $this->post("/deals/{$this->deal->getKey()}/people", [
        'team_membership_id' => $claire->getKey(),
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DealParticipant::query()->count())->toBe(1);
});

it('allows the same person in a second role, because people do hold two parts', function (): void {
    $claire = directoryEntry();

    $roster = app(DealRoster::class);
    $roster->add($this->deal, $claire, ParticipantRole::Seller);
    $roster->add($this->deal, $claire, ParticipantRole::Attorney);

    expect(DealParticipant::query()->where('team_membership_id', $claire->getKey())->count())->toBe(2);
});

it('tells the modal which roles somebody already holds, before the choice', function (): void {
    $claire = directoryEntry();

    app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller);

    $this->getJson("/deals/{$this->deal->getKey()}/people/candidates?q=Claire")
        ->assertOk()
        ->assertJsonPath('candidates.0.heldRoles', ['Seller']);
});

/**
 * One primary per role, and setting a new one means replacing the old one.
 */
it('demotes the incumbent when a new main contact is set', function (): void {
    $roster = app(DealRoster::class);

    $first = $roster->add($this->deal, directoryEntry('Claire'), ParticipantRole::Seller, isPrimary: true);
    $second = $roster->add($this->deal, directoryEntry('Sam'), ParticipantRole::Seller, isPrimary: true);

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue();
});

it('keeps a main contact per role rather than per deal', function (): void {
    // Two roles, two mains. A message to "the Seller" and one to "the Buyer"
    // resolve to different people, which is the point of the column.
    $roster = app(DealRoster::class);

    $seller = $roster->add($this->deal, directoryEntry('Claire'), ParticipantRole::Seller, isPrimary: true);
    $agent = $roster->add($this->deal, directoryEntry('Sam'), ParticipantRole::CoAgent, isPrimary: true);

    expect($seller->fresh()->is_primary)->toBeTrue()
        ->and($agent->fresh()->is_primary)->toBeTrue();
});

it('refuses to move somebody into a role they already hold here', function (): void {
    $claire = directoryEntry();

    $roster = app(DealRoster::class);
    $roster->add($this->deal, $claire, ParticipantRole::Seller);
    $second = $roster->add($this->deal, $claire, ParticipantRole::Attorney);

    // A sentence, not a constraint violation.
    $this->patch("/deals/{$this->deal->getKey()}/people/{$second->getKey()}", [
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertSessionHasErrors('participant_role');

    expect($second->fresh()->participant_role)->toBe(ParticipantRole::Attorney);
});

/**
 * IA §7: Remove detaches, Delete destroys.
 */
it('clears the notes when a partial update sends an empty one', function (): void {
    /*
     * The mirror image of the test below, and the case that made the first fix
     * wrong. `ConvertEmptyStringsToNull` turns `notes: ''` into null before
     * anything in the app sees it, so reading null as "not sent" made the
     * notes unclearable. Presence is what survives the coercion.
     */
    $participant = app(DealRoster::class)->add(
        $this->deal,
        directoryEntry(),
        ParticipantRole::Seller,
        notes: 'Prefers evenings.',
    );

    $this->patch("/deals/{$this->deal->getKey()}/people/{$participant->getKey()}", [
        'participant_role' => ParticipantRole::Seller->value,
        'notes' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($participant->fresh()->notes)->toBeNull();
});

it('leaves alone what a partial update did not send', function (): void {
    // `SavePerson::applyIdentity()` states the rule: a partial update must not
    // blank a column the screen did not show. A PATCH carrying only a role
    // used to demote a main contact and erase their notes.
    $claire = directoryEntry();

    $participant = app(DealRoster::class)
        ->add($this->deal, $claire, ParticipantRole::Seller, isPrimary: true, notes: 'Prefers evenings.');

    $this->patch("/deals/{$this->deal->getKey()}/people/{$participant->getKey()}", [
        'participant_role' => ParticipantRole::Buyer->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($participant->fresh()->is_primary)->toBeTrue()
        ->and($participant->fresh()->notes)->toBe('Prefers evenings.')
        ->and($participant->fresh()->participant_role)->toBe(ParticipantRole::Buyer);
});

it('removes a participant without touching the directory', function (): void {
    $claire = directoryEntry();

    $participant = app(DealRoster::class)->add($this->deal, $claire, ParticipantRole::Seller);

    $this->delete("/deals/{$this->deal->getKey()}/people/{$participant->getKey()}")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(DealParticipant::query()->count())->toBe(0)
        // Still in the directory, and still reachable.
        ->and(TeamMembership::query()->whereKey($claire->getKey())->exists())->toBeTrue();
});

it('frees the pairing again once somebody is removed', function (): void {
    $claire = directoryEntry();

    $roster = app(DealRoster::class);
    $participant = $roster->add($this->deal, $claire, ParticipantRole::Seller);

    $roster->remove($participant);

    // The unique index is partial on `deleted_at`, so re-adding works.
    $roster->add($this->deal, $claire, ParticipantRole::Seller);

    expect(DealParticipant::query()->count())->toBe(1);
});

it('timelines who joined and who left', function (): void {
    // Activity, not audit: "when did the lender join" is ordinary work a team
    // reads back, not a security event.
    $claire = directoryEntry();

    $roster = app(DealRoster::class);
    $participant = $roster->add($this->deal, $claire, ParticipantRole::Lender);
    $roster->remove($participant);

    expect(ActivityEvent::query()->whereIn('event_type', ['participant.added', 'participant.removed'])
        ->orderBy('created_at')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['participant.added', 'participant.removed']);
});

it('refuses a deal type side it has no opinion about, without crashing', function (): void {
    // `other` is a real seeded side once a team adds its own type.
    $type = DealType::factory()->create([
        'team_id' => $this->team->getKey(),
        'side' => DealSide::Other,
    ]);

    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->get("/deals/{$deal->getKey()}/people")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('missingRoles', 0));
});
