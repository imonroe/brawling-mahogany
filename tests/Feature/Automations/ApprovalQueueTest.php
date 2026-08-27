<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * S47, S48, S49 — the approval queue (PRD §4.5 F5.7, F5.8 · issue #93).
 *
 * PRD §4.5 calls this a **launch blocker, not an enhancement**: it is the
 * screen standing between an automation and a client's inbox, and the two
 * things issue #93 says it must never become are a list somebody clears
 * without reading, and a list that hides why a message is being held.
 */
beforeEach(function (): void {
    Queue::fake();

    [$this->team, $this->colleague] = $this->teamWithMember();

    /*
     * Signed in as the **owner**, because `message.approve` is a Team Owner
     * permission by default — F5.7 is about somebody taking responsibility for
     * a message reaching a client, and the catalogue puts that with running
     * the team rather than working in it. `$this->colleague` is the ordinary
     * Team Member, and the read-without-release case below uses them rather
     * than detaching a permission, which would be testing a role this product
     * does not ship.
     */
    $this->approver = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole())->person;

    $this->actingAsPerson($this->enrollTwoFactor($this->approver), $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

function queued(array $attributes = []): ActionInstance
{
    return ActionInstance::factory()->awaitingApproval()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);
}

it('lists what is waiting, oldest first', function (): void {
    $older = queued(['created_at' => now()->subHour()]);
    $newer = queued(['created_at' => now()]);

    $this->get('/messages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Messages/Queue')
            ->has('waiting', 2)
            ->where('waiting.0.id', $older->getKey())
            ->where('waiting.1.id', $newer->getKey()));
});

it('shows what did not go out beside what is waiting', function (): void {
    /*
     * *"Has the client been told?"* is PRD §1.1's second question, and a
     * message that failed answers it exactly as badly as one still waiting.
     * Putting the failures behind their own tab is how a team goes a
     * fortnight without noticing their mail credentials expired.
     */
    ActionInstance::factory()->failed('The mail transport rejected this message.')->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('recent', 1)
        ->where('recent.0.state', AutomationState::Failed->value));
});

it('releases a message and queues it', function (): void {
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval")->assertRedirect();

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->approved_by)->toBe($this->approver->getKey());

    Queue::assertPushed(RunAutomation::class);
});

it('audits who released it', function (): void {
    // PRD §9 asks for this by name: somebody took responsibility for something
    // reaching a client, and that has to outlive the activity feed's retention.
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval");

    expect(AuditEntry::query()->where('action', 'message.approved')->sole()->actor_person_id)
        ->toBe($this->approver->getKey());
});

it('refuses to release a message twice', function (): void {
    /*
     * Two people opening S47 at once is the ordinary case rather than a race
     * — it is a shared list and both see the same top item. The second one is
     * told, and nothing is sent again.
     */
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval");
    $this->post("/messages/{$instance->getKey()}/approval")
        ->assertSessionHas('error');

    Queue::assertPushed(RunAutomation::class, 1);
});

it('blocks approval while a merge field is unfilled', function (): void {
    // #93, in as many words: "a missing merge field blocks approval."
    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'unresolved' => ['property_address'],
        ],
    ]);

    $this->post("/messages/{$instance->getKey()}/approval")
        ->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval);
    Queue::assertNothingPushed();
});

it('lets an approver fix the words before releasing it', function (): void {
    /*
     * F5.10 pre-fills a message *"ready to review and send"*, and reviewing
     * means being able to change it. What changes is **this instance's**
     * payload: two instances raised from one template are two messages to two
     * clients, and fixing the sentence about this deal must not rewrite the
     * words every future deal gets.
     */
    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'unresolved' => ['property_address'],
            'bodyText' => 'Your listing at {{ property_address }} is live.',
        ],
    ]);

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'Your listing at 14 Cedar Row is live.',
    ])->assertSessionMissing('error');

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->rendered()->bodyText)->toBe('Your listing at 14 Cedar Row is live.')
        // The list described the old words. Carrying it over would go on
        // blocking an approval that is now sound.
        ->and($instance->fresh()->rendered()->unresolved)->toBe([]);
});

it('refuses an edit that introduces a dropped brace', function (): void {
    /*
     * An approver typing into a textarea can produce PR #175's defect as
     * easily as a template author can: `{{ client_name }` saves clean, renders
     * verbatim, and reaches the client as the template's internals. The render
     * that would have caught it happened before they typed.
     */
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'Hello {{ client_name }, your listing is live.',
    ])->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval);
});

it('refuses a merge field typed into an edit', function (): void {
    /*
     * The hazard that is easy to miss, and the first version of the edit path
     * did miss it. The payload is text that has **already been substituted** —
     * the render happened at raise time so F5.10 could pre-fill it — so a
     * token typed here has nothing left to replace it and reaches the client
     * exactly as written. Registered or not is beside the point: it goes out
     * as braces either way.
     */
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'Your closing is on {{ closing_date }}.',
    ])->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval);
});

it('leaves a field alone when the form does not post it', function (): void {
    /*
     * `array_key_exists` rather than a null check: a form that posts no
     * `bodyHtml` is *"I did not touch the HTML"*, and one posting an empty
     * string is *"I deleted it"*. Collapsing the two would let a push message,
     * which carries no HTML body at all, silently blank a field it never had.
     */
    $instance = queued();
    $original = $instance->rendered()->bodyHtml;

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'Rewritten entirely.',
    ]);

    expect($instance->fresh()->rendered()->bodyHtml)->toBe($original);
});

it('marks a manual prompt done without queueing anything', function (): void {
    // F5.4: presented to a human rather than fired, and "recorded identically
    // once done". There is nothing for a worker to carry out.
    $instance = queued(['action_type' => 'manual_prompt', 'payload' => []]);

    $this->post("/messages/{$instance->getKey()}/approval")->assertSessionMissing('error');

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        ->and($instance->fresh()->executed_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('stops a message rather than deleting it', function (): void {
    /*
     * IA §7's **Cancel**, which is not Delete. S49 has to be able to answer
     * *"why did the client never hear about this"* months later, and a deleted
     * row answers nothing.
     */
    $instance = queued();

    $this->delete("/messages/{$instance->getKey()}/approval", [
        'reason' => 'The sellers asked us not to announce it.',
    ])->assertRedirect();

    expect($instance->fresh()->state)->toBe(AutomationState::Cancelled)
        ->and($instance->fresh()->error)->toBe('The sellers asked us not to announce it.')
        ->and(ActionInstance::query()->count())->toBe(1);
});

it('will not stop a message that has already gone', function (): void {
    // Marking it cancelled would be the record saying a client was not told
    // something they were told.
    $instance = ActionInstance::factory()->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->delete("/messages/{$instance->getKey()}/approval")->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::Sent);
});

it('shows one message and everything that happened to it', function (): void {
    $instance = ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->get("/messages/{$instance->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Messages/Show')
            ->where('message.state', AutomationState::Failed->value)
            ->where('message.error', 'This message resolved to nobody on this deal.')
            ->has('message.rendered'));
});

it('explains a withheld copy on S49, in words about the address', function (): void {
    /*
     * Round 2 of review: `withheld_reason` was asserted as a column value and
     * rendered by no test — so the complaint sentence round 1 blocked on
     * (*"a message from **you** … somebody who has reported **you**"*, shown
     * to a team with nothing to do with the complaint) had a second surface
     * where it could come back.
     */
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    App\Models\MessageDelivery::factory()->create([
        'team_id' => $this->team->getKey(),
        'action_instance_id' => $instance->getKey(),
        'recipient_email' => 'dana@example.test',
        'provider_message_id' => null,
        'status' => App\Enums\DeliveryStatus::Suppressed,
        'withheld_reason' => App\Enums\SuppressionReason::Complaint,
    ]);

    $this->get("/messages/{$instance->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Messages/Show')
            ->where('deliveries.0.status', 'suppressed')
            ->where('deliveries.0.isFailure', true)
            ->where(
                'deliveries.0.explanation',
                'This was not sent. This address has been reported as receiving unwanted mail, '
                .'so nothing further will be sent to it. If you need to reach them, use a phone '
                .'number or ask them to write to you first.',
            ));
});

it('shows an approver the frame the client will see, not only the words', function (): void {
    /*
     * F5.7's promise is that what an approver reads is what the client gets,
     * and `MilestoneAnnouncement` rests its own argument on *"what an approver
     * reads on S48 **is the payload**"*. That was true of the words and false
     * of everything around them: S87's headline, the address under it and the
     * *"View the listing"* button reached a client having been seen by nobody.
     */
    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'milestone' => [
                'headline' => 'Your home is on the market',
                'propertyAddress' => '12 Oak Lane, Golden, CO 80401',
                'mlsLink' => 'https://mls.example.test/listing/8891',
                'statusPageLink' => null,
            ],
        ],
    ]);

    $this->get("/messages/{$instance->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('message.milestone.headline', 'Your home is on the market')
            ->where('message.milestone.propertyAddress', '12 Oak Lane, Golden, CO 80401')
            ->where('message.milestone.mlsLink', 'https://mls.example.test/listing/8891'));
});

it('does not show a link the words already carry, the way the email does not send it', function (): void {
    /*
     * The preview reads the payload through the same suppression the mailable
     * does, so the approver sees the message as it will actually go — one copy
     * of the listing URL, not the frame's button beside the team's own.
     */
    $url = 'https://mls.example.test/listing/8891';

    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'bodyText' => 'See the listing: '.$url,
            'milestone' => [
                'headline' => 'Your home is on the market',
                'propertyAddress' => null,
                'mlsLink' => $url,
                'statusPageLink' => null,
            ],
        ],
    ]);

    $this->get("/messages/{$instance->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('message.milestone.mlsLink', null));
});

it('says nothing about a frame for an ordinary message', function (): void {
    $this->get('/messages/'.queued()->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('message.milestone', null));
});

it('gives every list on the queue a tiebreaker its sort column cannot provide', function (): void {
    /*
     * `created_at`, `executed_at` and `updated_at` are all `timestamp(0)`, so a
     * busy second puts several rows in one. A sort with no tiebreaker leaves
     * Postgres free to return heap order — stable only until something churns
     * the pages — so the queue reorders under a reader between two refreshes
     * over identical data, and `MessageQueueBudgetTest` failed depending on
     * what had run before it.
     *
     * **Asserted against the SQL rather than against a returned order**, and
     * that is the honest form. Two earlier versions of this test seeded rows
     * sharing a second and compared the ids that came back; both passed with
     * every tiebreaker removed, because a freshly inserted table hands them
     * back in insertion order anyway and an `UPDATE` meant to disturb that is
     * a HOT update that does not move the tuple. Reproducing heap disorder on
     * demand is a fight with the planner and the page layout; what the fix
     * actually claims is that the ordering is *total*, and the query is where
     * that is either true or not.
     *
     * `id` is a ULID, so the tiebreaker is a creation-order tiebreaker too.
     */
    queued();

    $sorts = [];

    DB::listen(function ($query) use (&$sorts): void {
        if (str_contains($query->sql, 'from "action_instances"') && str_contains($query->sql, 'order by')) {
            $sorts[] = mb_substr($query->sql, mb_strpos($query->sql, 'order by'));
        }
    });

    $this->get('/messages')->assertOk();

    expect($sorts)->not->toBeEmpty();

    foreach ($sorts as $sort) {
        expect($sort)->toContain('"id"');
    }
});

it('lets somebody read the queue without being able to release from it', function (): void {
    /*
     * Two permissions rather than one. Reading a queued message shows nothing
     * a `deals.view` holder cannot already see on the deal; **releasing** one
     * is the moment somebody takes responsibility for it reaching a client,
     * which is what `message.approve` is for.
     */
    $instance = queued();

    // A Team Member: `deals.view` without `message.approve`, which is the
    // shipped split rather than a role invented for this test.
    $this->actingAsPerson($this->colleague, $this->team);

    $this->get('/messages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.approve', false));

    $this->post("/messages/{$instance->getKey()}/approval")->assertForbidden();

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval);
});

it('never shows another team’s queued messages', function (): void {
    [$otherTeam, $otherMember] = $this->teamWithMember();

    $instance = queued();

    app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): void {
        ActionInstance::factory()->awaitingApproval()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => Deal::factory()->create(['team_id' => $otherTeam->getKey()])->getKey(),
        ]);
    });

    $this->actingAsPerson($otherMember, $otherTeam);

    $this->get("/messages/{$instance->getKey()}")->assertNotFound();

    $this->get('/messages')->assertInertia(fn ($page) => $page->has('waiting', 1));

    unset($mine);
});

it('counts what is waiting on the shell, for every screen', function (): void {
    /*
     * Design System §10.4's badge, and the reason it earns a query on every
     * request: a queue nobody is told about is a set of client messages that
     * silently never go, which is a worse failure than the one the queue
     * prevents because it is invisible.
     */
    queued();
    queued();

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('counts.pendingMessages', 2));
});

it('offers no route that approves in bulk', function (): void {
    /*
     * #93 names the failure mode: *"bulk approve teaches people to approve
     * without reading."* A route taking an array of ids would be that feature
     * whatever the screen chose to draw, so the absence is asserted rather
     * than left to the front end.
     */
    $names = collect(app('router')->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'messages.'));

    expect($names->values()->all())
        ->toBe(['messages.index', 'messages.show', 'messages.approve', 'messages.cancel']);
});

it('offers no route that deletes a message', function (): void {
    $instance = queued();

    /*
     * The only DELETE on a message is `cancel`, which stops it and keeps the
     * row. `/messages/{id}` answers **405** rather than 404 because the URI
     * exists for GET — which is the framework saying there is no such verb
     * here, and the assertion this rule actually wants. The route-name check
     * above is the other half: a `messages.destroy` could not hide behind a
     * different path.
     */
    $this->delete("/messages/{$instance->getKey()}")->assertMethodNotAllowed();

    unset($instance);
});

it('refuses an edit while a field the approver never touched is malformed', function (): void {
    /*
     * PR #175's finding arriving through the approval path instead of the
     * template editor. `applyEdits()` assigned `malformed = []` with a comment
     * claiming it was recomputed — the comment describing the intention and
     * the code doing the opposite, since `strayBraceRuns()` only ever ran
     * against the value being edited.
     *
     * So an approver who fixed the body released a **subject** still carrying
     * `{{ client_name }`, past the `isComplete()` check the rails depend on,
     * and the client read the template's own internals in their inbox.
     */
    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'subject' => 'Update on {{ client_name }',
            'malformed' => ['{{'],
        ],
    ]);

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'The inspection is booked for Friday.',
    ])->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval)
        ->and($instance->fresh()->rendered()->malformed)->toBe(['{{']);
});

it('clears a stale malformed entry once the words behind it are fixed', function (): void {
    // The other direction, and the one that makes "recomputed" true rather
    // than "cleared": a list that only ever grew would block an approval that
    // is now sound.
    $instance = queued([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'bodyText' => 'Hello {{ client_name }, your inspection is booked.',
            'malformed' => ['{{'],
        ],
    ]);

    $this->post("/messages/{$instance->getKey()}/approval", [
        'bodyText' => 'Hello Dana, your inspection is booked.',
    ])->assertSessionMissing('error');

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->rendered()->malformed)->toBe([]);
});

it('keeps a failure on the screen however much has been sent since', function (): void {
    /*
     * The failures used to be filtered out of the 25 most-recent rows
     * client-side, so a team that sent 25 messages after a failure lost it off
     * the screen entirely — while `automation.md` promises *"it is the thing
     * you most need to notice"*. A list that silently drops the row it exists
     * for is worse than no list.
     */
    ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'executed_at' => now()->subDays(2),
    ]);

    ActionInstance::factory()->count(30)->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('failing', 1)
        ->where('totals.failing', 1));
});

it('names a message a rail is holding', function (): void {
    /*
     * `pending` rows were on no list on S47, which only worked while every one
     * of them was being retried every minute. Now that a message behind the
     * kill switch or a ceiling is deliberately left alone, it is idle — and
     * before this it surfaced only as a single integer on `/settings/sending`
     * framed as *what the switch would catch*. A team over its daily ceiling
     * would have client messages that no screen named, indefinitely.
     */
    ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'state' => AutomationState::Pending,
        'error' => 'This team has reached its limit of messages for the day. Sending is paused, not cancelled.',
    ]);

    // And one that is simply on its way, which is not held and must not be
    // reported as though somebody has to do something about it.
    ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('held', 1)
        ->where('totals.held', 1)
        ->where('held.0.error', 'This team has reached its limit of messages for the day. Sending is paused, not cancelled.'));
});

it('refuses an approval that empties the subject line', function (): void {
    /*
     * `null` means a channel that never had a subject; `''` is the one a
     * person can produce, from S48's own field. The mailable's fallback guards
     * null only, so an emptied subject reached the wire as a message with no
     * subject line at all — from the screen that exists so the words get read
     * before they go.
     */
    $instance = queued();

    $this->post("/messages/{$instance->getKey()}/approval", [
        'subject' => '   ',
    ])->assertSessionHas('error');

    expect($instance->fresh()->state)->toBe(AutomationState::AwaitingApproval)
        ->and($instance->fresh()->rendered()->subject)->toBe('Your inspection is scheduled');
});

it('names a message handed to a transport and never confirmed', function (): void {
    /*
     * The final form of round 2's blocker. The row cannot be narrated by a
     * worker — three rounds established that — so it is *listed* instead, and
     * told apart from a message a rail is merely holding: one of those goes
     * out on its own when the reason clears and the other never will.
     */
    ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'state' => AutomationState::Pending,
        'message_key' => 'claimed-and-never-confirmed',
    ]);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('held', 1)
        ->where('held.0.isUnconfirmed', true));
});
