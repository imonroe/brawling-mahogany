<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Notification;
use App\Models\Property;
use App\Support\Push\PushPayload;

/**
 * #103's definition of done: *"a test asserts no PII in the push payload."*
 *
 * The rule it holds, in the issue's own words: a push body sits on a
 * third-party push service and on a lock screen, so *"123 Main St has an
 * overdue task"* is fine and a client's name, phone number or figure is not.
 *
 * ## A Feature test, and it was written as a Unit one by mistake
 *
 * `docs/Testing.md`: Unit is *"pure logic"*, and *"everything but Unit runs
 * against a real Postgres"*. This builds a team, a deal, a property and a
 * notification, so it was never a unit test — and the mistake was invisible
 * locally, where the development database is already migrated, and fatal in
 * CI, where the Unit suite has no schema at all (`relation "teams" does not
 * exist`).
 *
 * Worth recording rather than quietly moving: *"it passes on my machine"* had
 * a specific mechanism here, and the suite a test lives in is a claim about
 * what it needs rather than a folder preference.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

it('says which property, and nothing else about the deal', function (): void {
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $property = Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '123 Main St',
    ]);

    // `forceFill`, because `team_id` is never fillable (CLAUDE.md: the trait
    // fills it) and this model carries an empty `#[Fillable]`.
    (new DealProperty)->forceFill([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'property_id' => $property->getKey(),
        'is_subject' => true,
    ])->save();

    $notification = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $deal->getKey(),
        'type' => NotificationType::TaskAssigned->value,
        'summary' => 'You were assigned “Call Dana Okafor about the appraisal”',
    ]);

    $payload = PushPayload::for($notification);

    expect($payload['body'])->toContain('123 Main St')
        // The summary is free text composed from a task title, a gate label
        // or an automation's subject — every one of them a field somebody
        // typed a client's name into. It never reaches a lock screen.
        ->and($payload['body'])->not->toContain('Dana')
        ->and($payload['body'])->not->toContain('Okafor')
        ->and(json_encode($payload))->not->toContain('Dana');
});

it('never puts a client’s surname on a lock screen', function (): void {
    /*
     * The trap this class exists for. `Deal::displayName()` falls back to
     * `generated_name`, and `NameDeal` derives that from the subject
     * property's street **or, failing that, the client's surname** — so the
     * obvious one-liner pushes a surname for every deal with no property
     * attached, which is every buy-side deal before an offer.
     */
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'name' => null,
        'generated_name' => 'Okafor purchase',
    ]);

    $notification = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $deal->getKey(),
        'type' => NotificationType::DeadlineApproaching->value,
    ]);

    $payload = PushPayload::for($notification);

    expect(json_encode($payload))->not->toContain('Okafor')
        // Still says what happened — a push with no content is one somebody
        // has to open the app to triage, which defeats sending it.
        ->and($payload['title'])->toBe(NotificationType::DeadlineApproaching->label())
        ->and($payload['body'])->not->toBe('');
});

it('is built only from the type and the street', function (): void {
    /*
     * An allowlist rather than a blocklist, asserted as one: every string in
     * the payload has to be traceable to a constant in the enum, the street,
     * or an opaque id. A test that only checked *"does not contain this
     * client's name"* would pass the day somebody adds a field carrying a
     * different one.
     */
    $notification = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => null,
        'type' => NotificationType::AutomationFailed->value,
        'summary' => 'Sensitive free text nobody vetted',
        'data' => ['secret' => 'also not for a lock screen'],
    ]);

    $payload = PushPayload::for($notification);

    expect($payload)->toBe([
        'title' => NotificationType::AutomationFailed->label(),
        'body' => NotificationType::AutomationFailed->description(),
        'url' => '/notifications',
        'tag' => NotificationType::AutomationFailed->value.':none',
    ]);
});
