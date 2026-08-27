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
        ->and($payload['title'])->toBe('Due tomorrow')
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

    /*
     * **Literals, not `->pushTitle()` and `->pushBody()`.**
     *
     * Round 1 of review caught the first version asserting
     * `toBe($type->description())`, which is true of *whatever
     * `description()` returns* — including the S78 preference copy it was
     * actually returning, so the test passed while a lock screen read *"A
     * task is assigned to me / When somebody assigns you a task, or reassigns
     * one to you."* `CLAUDE.md` names the shape: a test that asserts
     * `config(X)` cannot see a wrong value in `X`, and the same is true of an
     * enum method.
     *
     * Spelling the strings out means somebody changing this copy has to look
     * at it on the surface it lands on.
     */
    expect($payload)->toBe([
        'title' => 'An automation needs looking at',
        'body' => 'Open it to see what happened.',
        'url' => '/notifications',
        'tag' => 'automation_failed:none',
    ]);
});

it('never reuses S78’s preference copy on a lock screen', function (): void {
    /*
     * The finding behind `pushTitle()`/`pushBody()` existing at all.
     *
     * `label()` and `description()` are written for **S78's preference
     * rows**, where each completes *"tell me when…"* — so `label()` reads *"A
     * task is assigned to me"* and `description()` explains a **setting**
     * rather than an event. Rendered onto a phone announcing something that
     * has just happened, the pair is nonsense.
     *
     * A guard rather than a memory, because the two pairs of methods sit in
     * one enum and the wrong one is always in reach.
     */
    foreach (NotificationType::cases() as $type) {
        expect($type->pushTitle())->not->toBe($type->label())
            ->and($type->pushBody())->not->toBe($type->description())
            // The tell-tale of preference copy: it starts by naming the
            // condition rather than the event.
            ->and($type->pushBody())->not->toStartWith('When ')
            /*
             * S78's titles complete *"tell me when…"* and so end in the
             * first person — *"A task is assigned to me"*, *"Something of
             * mine is due soon"*. A push announces an event to somebody, so
             * it never does.
             *
             * `toEndWith`, not `toContain`: the first version used
             * `not->toContain(' me')`, which flagged *"A message did not go
             * out"* — the substring is inside "message". A heuristic that
             * fires on correct copy is one somebody edits the copy to
             * satisfy.
             */
            ->and($type->pushTitle())->not->toEndWith(' me')
            ->and($type->pushTitle())->not->toEndWith(' mine');
    }
});

it('never asserts what went wrong with an automation', function (): void {
    /*
     * `CLAUDE.md` states this rule and names the failing sentence:
     *
     * > **A headline that asserts is wrong for one caller.** *"An automated
     * > message did not go out"* is false over the reaper's *"it may have
     * > reached the recipient"*, and false again for a `create_task` that
     * > involved no message.
     *
     * A delivery bounce is a third case: the message *did* go out. Round 3 of
     * review caught `pushTitle()` reintroducing exactly that phrasing on a
     * lock screen, one surface along from the alert the rule was written for.
     *
     * Held against `AlertOnFailures`' own words rather than a list of banned
     * phrases, because the point is that the two say the **same** thing — a
     * person who gets the email and the push about one failure should not be
     * told two different stories about it.
     */
    $title = NotificationType::AutomationFailed->pushTitle();
    $body = NotificationType::AutomationFailed->pushBody();

    expect($title)->toContain('needs looking at');

    foreach ([$title, $body] as $line) {
        expect($line)->not->toContain('did not go out')
            ->and($line)->not->toContain('failed to send')
            ->and($line)->not->toContain('was not sent');
    }
});
