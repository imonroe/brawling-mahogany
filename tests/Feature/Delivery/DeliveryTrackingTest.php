<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Enums\DeliveryStatus;
use App\Enums\SuppressionReason;
use App\Models\ActionInstance;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\MessageDelivery;
use App\Models\SuppressedAddress;
use App\Support\Automation\ExecuteAction;
use Illuminate\Support\Facades\Mail;

/**
 * Delivery tracking and the suppression list (PRD §4.5 F5.8 · issue #95).
 *
 * PRD §1.1 says this product answers two questions and the second is *"has the
 * client been told?"*. Everything here is about the gap between the answer
 * `action_instances` can give — *we handed it over* — and the one a team
 * actually needs.
 */
beforeEach(function (): void {
    /*
     * **The real array transport, not `Mail::fake()`**, and this is a finding
     * rather than a preference.
     *
     * A fake's `send()` returns `null` — there is no `SentMessage`, because
     * nothing was handed to a transport — so `provider_message_id` is null for
     * every send under it. Written with a fake, every test here would pass
     * against a send path that never captured the id at all, and the
     * correlation the whole feature rests on would be exercised by nothing.
     *
     * It is the same trap `tests/Feature/Mail/` records one layer along: a
     * fake proves a mailable was handed over, and nothing about what happened
     * when it was. Nothing escapes — the array transport keeps every message
     * in memory.
     */
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->team->forceFill([
        'sandbox_mode' => false,
        'approval_required_until' => now()->subDay(),
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->save();

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

function sendable(array $recipients, array $attributes = []): ActionInstance
{
    $instance = ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);

    $payload = $instance->payload;
    $payload['recipients'] = $recipients;

    $instance->forceFill(['payload' => $payload])->save();

    return $instance;
}

it('records one delivery row per address, not one per message', function (): void {
    /*
     * The shape the whole feature turns on. `action_instances` has a single
     * `state`, and a stage-completion email to two sellers can reach one and
     * bounce off the other — so the row that answers "did it arrive" has to be
     * per recipient or it cannot express the case it exists for.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
        ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $deliveries = MessageDelivery::query()->get();

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        ->and($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('recipient_email')->sort()->values()->all())
        ->toBe(['dana@example.test', 'sam@example.test'])
        // Both carry the id the provider gave the one message it accepted,
        // which is how a bounce naming that message fans out to the recipients.
        ->and($deliveries->pluck('provider_message_id')->unique())->toHaveCount(1)
        ->and($deliveries->first()->provider_message_id)->not->toBeNull()
        ->and($deliveries->pluck('status')->unique()->all())->toBe([DeliveryStatus::Sent]);
});

it('writes the provider’s id onto the instance, and never confuses it with the key', function (): void {
    /*
     * `message_key` is ours and is written **before** the mailer is called;
     * `provider_message_id` is theirs and arrives after. The idempotency
     * guarantee rests on the first, and #95's correlation on the second — a
     * send that timed out has a key and no provider id, which is exactly the
     * case that must never be retried.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $sent = $instance->fresh();

    expect($sent->message_key)->not->toBeNull()
        ->and($sent->provider_message_id)->not->toBeNull()
        ->and($sent->provider_message_id)->not->toBe($sent->message_key);
});

it('will not write to a suppressed address, whichever team is asking', function (): void {
    /*
     * The account-wide rule, and the reason `suppressed_addresses` has no
     * `team_id`. A hard bounce is a fact about the mailbox; a second team
     * rediscovering it spends the whole account's deliverability, which SES
     * measures per account (PRD §12.2).
     *
     * The suppression here is discovered by **another** team entirely, which
     * is what makes this a test of the cross-tenant rule rather than of a
     * scope that happens to be missing.
     */
    [$otherTeam] = $this->teamWithMember();

    SuppressedAddress::factory()->create([
        'email' => 'dana@example.test',
        'discovered_by_team_id' => $otherTeam->getKey(),
    ]);

    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'DANA@Example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);

    $failed = $instance->fresh();

    expect($failed->state)->toBe(AutomationState::Failed)
        // In words about the address, and never about the other team.
        ->and($failed->error)->toContain('can no longer be written to')
        ->and($failed->error)->toContain('does not exist')
        ->and($failed->error)->not->toContain($otherTeam->name)
        ->and(MessageDelivery::query()->count())->toBe(0);
});

it('drops the dead address, writes to the live one, and says so everywhere', function (): void {
    /*
     * The half that matters more than the refusal. A deal with two sellers,
     * one of whose mailbox has died, must still reach the other — letting one
     * dead address silence a perfectly reachable client is the failure this
     * product cares about most.
     *
     * **And the drop has to be visible.** Round 1 of review: the first version
     * narrowed the list in silence, so the timeline read *"Emailed Dana
     * Okafor, Sam Reilly"* over a send Dana never received, the audit counted
     * one recipient with no note of the other, and S49 listed one delivery
     * under a "Goes to" naming two. PRD §1.1's second question is *"has the
     * client been told?"* and the answer for Dana was nowhere at all.
     *
     * The old version of this test asserted the row count and the surviving
     * address, which is exactly what that implementation produced — so it
     * passed against the defect. Everything below the count is the part that
     * would have caught it.
     */
    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);

    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
        ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $byEmail = MessageDelivery::query()->get()->keyBy('recipient_email');

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        // A row each: one sent, one recording that it never was.
        ->and($byEmail)->toHaveCount(2)
        ->and($byEmail['sam@example.test']->status)->toBe(DeliveryStatus::Sent)
        ->and($byEmail['dana@example.test']->status)->toBe(DeliveryStatus::Suppressed)
        ->and($byEmail['dana@example.test']->withheld_reason)->toBe(SuppressionReason::HardBounce)
        ->and($byEmail['dana@example.test']->provider_message_id)->toBeNull();

    // Only Sam was written to, and Sam is who the timeline names.
    $sent = ActivityEvent::query()->where('event_type', 'message.sent')->sole();

    expect($sent->summary)->toContain('Emailed Sam Reilly')
        // Named **with the address**, which is the thing to go and correct —
        // and the only field that stays unambiguous when two people on a deal
        // share a display name. See the Sr./Jr. case below.
        ->and($sent->summary)->toContain('Dana Okafor (dana@example.test) was not written to')
        ->and($sent->summary)->not->toContain('Emailed Dana Okafor');

    // And the audit records both halves rather than only the survivors.
    $entry = App\Models\AuditEntry::query()->where('action', 'message.sent')->sole();

    expect($entry->after['recipient_count'])->toBe(1)
        ->and($entry->after['withheld_count'])->toBe(1);
});

it('does not alert every time a message is addressed to a dead address', function (): void {
    /*
     * Round 2 of review reversing round 1's reasoning, and worth keeping as a
     * trade rather than a correction.
     *
     * Round 1 stamped `noticed_at` on a withheld row so S91's sweep would
     * carry it, arguing that the bounce may be old news. True — and a withheld
     * row is written on **every** send to that address, while the watermark
     * only stops the same *row* being reported twice. Round 2 measured three
     * sends producing three alerts; for a Keep in Touch schedule against a
     * past client whose mailbox died, that is an alert every cycle forever,
     * about a fact the product already recorded and is deliberately acting on.
     *
     * It is not silent. The deal's timeline names who was not written to in
     * the same breath as the send, and S49 lists the row with its reason —
     * both pull surfaces, true whenever somebody looks, which is what a
     * standing condition wants.
     */
    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);

    $withheld = null;

    foreach (range(1, 3) as $round) {
        $instance = sendable([
            ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
            ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
        ]);

        app(ExecuteAction::class)->handle($instance, $this->team);

        $withheld = MessageDelivery::query()
            ->where('recipient_email', 'dana@example.test')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    expect(MessageDelivery::query()->where('recipient_email', 'dana@example.test')->count())->toBe(3)
        // Recorded three times, and reportable none of them.
        ->and($withheld->noticed_at)->toBeNull()
        ->and(
            MessageDelivery::query()
                ->failed()
                ->whereNotNull('noticed_at')
                ->count(),
        )->toBe(0)
        // The scope still sees them — `failed()` is derived from the enum, so
        // a third failure case cannot silently fall out of it. Its only
        // consumer today is the alert sweep, whose own `noticed_at` window is
        // what excludes these.
        ->and(MessageDelivery::query()->failed()->count())->toBe(3);
});

it('does not collapse two recipients who share a name', function (): void {
    /*
     * Round 2 of review, and round 1's blocker arriving a third time.
     *
     * The summary was composed with `array_diff` over **names**, which removes
     * every element equal to a withheld name — so Sr. and Jr. on one deal,
     * one of them suppressed, produced *"Emailed nobody"* over a message that
     * reached Jr. The audit was right and the sentence a person reads was
     * wrong, which is worse than silence.
     */
    SuppressedAddress::factory()->create(['email' => 'john.sr@example.test']);

    $instance = sendable([
        ['name' => 'John Smith', 'email' => 'john.sr@example.test', 'membershipId' => null],
        ['name' => 'John Smith', 'email' => 'john.jr@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $sent = ActivityEvent::query()->where('event_type', 'message.sent')->sole();

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        ->and($sent->summary)->toContain('Emailed John Smith:')
        ->and($sent->summary)->not->toContain('Emailed nobody')
        // And the address is what tells the two apart.
        ->and($sent->summary)->toContain('(john.sr@example.test) was not written to');
});

it('rehearses the skip under sandbox without recording it', function (): void {
    /*
     * Round 2 of review. Sandbox replaces *every* recipient, so nothing was
     * withheld from anybody — and carrying the list produced one client marked
     * "Not sent" beside another omitted entirely, plus an S91 alert about a
     * delivery failure during the one mode that exists so a team can exercise
     * automations without touching a client.
     *
     * It also produced a run-on sentence: the redirected branch ends on a
     * name, so *"rather than Sam Reilly Dana Okafor was not written to"* read
     * as one four-word name.
     */
    [$team, $owner] = $this->teamWithOwner();

    $team->forceFill([
        'sandbox_mode' => true,
        'approval_required_until' => now()->subDay(),
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->save();

    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);

    $instance = app(App\Support\Tenancy\TeamContext::class)->runFor($team, function () use ($team): ActionInstance {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        $instance = ActionInstance::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);

        $payload = $instance->payload;
        $payload['recipients'] = [
            ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
            ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
        ];
        $instance->forceFill(['payload' => $payload])->save();

        app(ExecuteAction::class)->handle($instance, $team);

        return $instance;
    });

    /*
     * Read inside that team's context: `beforeEach` resolved a different one,
     * and `MessageDelivery` is team-scoped — so an unscoped-looking `get()`
     * here would quietly return nothing and pass against anything.
     */
    [$rows, $event] = app(App\Support\Tenancy\TeamContext::class)->runFor($team, fn (): array => [
        MessageDelivery::query()->get(),
        ActivityEvent::query()->where('event_type', 'message.redirected')->sole(),
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->redirected)->toBeTrue()
        ->and($rows->first()->status)->toBe(DeliveryStatus::Sent);

    expect($event->summary)
        // Dana is **not** among the people a live send would have reached...
        ->toContain('went to the team rather than Sam Reilly.')
        // ...and the rehearsal says what would have happened to her instead.
        ->toContain('Dana Okafor (dana@example.test) would have been skipped')
        // In the conditional, never the past tense: nobody was written to.
        ->and($event->summary)->not->toContain('was not written to');
});

it('still rehearses when every address is suppressed, rather than failing', function (): void {
    /*
     * Round 3 of review found a cliff between one-of-two and two-of-two.
     * Round 2's argument for ignoring the withheld list under sandbox —
     * *"sandbox replaces every recipient, so nothing was withheld from
     * anybody"* — applies to Rail 0b's refusal as much as to its reporting,
     * and had only been applied to the reporting. So two dead addresses failed
     * the send permanently, with an S91 alert, in the mode that exists so a
     * team can exercise automations without touching a client.
     */
    [$team, $owner] = $this->teamWithOwner();

    $team->forceFill([
        'sandbox_mode' => true,
        'approval_required_until' => now()->subDay(),
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->save();

    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);
    SuppressedAddress::factory()->create(['email' => 'sam@example.test']);

    $instance = app(App\Support\Tenancy\TeamContext::class)->runFor($team, function () use ($team): ActionInstance {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        $instance = ActionInstance::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);

        $payload = $instance->payload;
        $payload['recipients'] = [
            ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
            ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
        ];
        $instance->forceFill(['payload' => $payload])->save();

        app(ExecuteAction::class)->handle($instance, $team);

        return $instance;
    });

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        ->and($instance->fresh()->error)->toBeNull();

    $event = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $team,
        fn () => ActivityEvent::query()->where('event_type', 'message.redirected')->sole(),
    );

    /*
     * **No "rather than nobody"** — round 4 of review pointed out that this
     * test had pinned round 2's *"Emailed nobody"* sentence in the branch
     * round 2's fix did not reach. With every address suppressed there is
     * nobody to name, so the clause naming them goes.
     */
    expect($event->summary)->toContain('went to the team.')
        ->and($event->summary)->not->toContain('nobody')
        ->and($event->summary)->toContain('Dana Okafor (dana@example.test) would have been skipped')
        ->and($event->summary)->toContain('Sam Reilly (sam@example.test) would have been skipped');
});

it('lets an escalation win a race it used to lose', function (): void {
    /*
     * Round 2 of review. Round 1's conditional UPDATE guarded on *"the status
     * I read"*, which is too narrow by exactly one case: two workers holding a
     * bounce and a complaint both read `sent`, the bounce won, and the
     * complaint — the more serious fact, and the one PRD §12.2 is about — was
     * dropped.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $id = MessageDelivery::query()->sole()->getKey();

    $bounceWorker = MessageDelivery::query()->findOrFail($id);
    $complaintWorker = MessageDelivery::query()->findOrFail($id);

    expect($bounceWorker->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550'))->toBeTrue()
        // Read `sent`, arrives second, and still lands: it outranks what is
        // there now.
        ->and($complaintWorker->advanceTo(DeliveryStatus::Complained, now()))->toBeTrue()
        ->and(MessageDelivery::query()->findOrFail($id)->status)->toBe(DeliveryStatus::Complained);
});

it('records a late delivery confirmation without moving the status back', function (): void {
    /*
     * The other half of the same predicate. A timestamp-only change has no
     * rank to compare, so it is claimed by its own column being null — which
     * keeps a late "delivered to the server" notification writing
     * `delivered_at` on the way past a row that has since bounced. Round 1's
     * comment claimed this and the code did the opposite.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    $delivery->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550');

    expect($delivery->fresh()->advanceTo(DeliveryStatus::Delivered, now()->subMinute()))->toBeTrue();

    $final = $delivery->fresh();

    expect($final->status)->toBe(DeliveryStatus::Bounced)
        ->and($final->delivered_at)->not->toBeNull()
        ->and($final->detail)->toBe('smtp; 550');
});

it('will not redirect a sandbox message to a suppressed team owner', function (): void {
    /*
     * Round 1 of review. Rail 0b filters the recipients and Rail 3 then
     * **replaces** them with the team owner — so the first version put back an
     * address the filter had just removed. Sandbox is the one mode where a
     * team's whole outbound volume converges on a single address, so a
     * suppressed owner would be re-mailed on every send: the account-wide rate
     * PRD §12.2 is about, damaged by the rail written to protect it.
     *
     * A **halt**, not a refusal: turning sandbox off releases the message.
     */
    [$team, $owner] = $this->teamWithOwner();

    $team->forceFill([
        'sandbox_mode' => true,
        'approval_required_until' => now()->subDay(),
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->save();

    SuppressedAddress::factory()->create(['email' => (string) $owner->email]);

    /*
     * Inside that team's context, because `beforeEach` resolved a different
     * one — `BelongsToTeam`'s cross-tenant guard is doing its job, and a
     * worker really does run under `RunsForTeam` rather than under a request.
     */
    $instance = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $team,
        function () use ($team): ActionInstance {
            $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

            $instance = ActionInstance::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
            ]);

            app(ExecuteAction::class)->handle($instance, $team);

            return $instance;
        },
    );

    $held = $instance->fresh();

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        // Held, not failed — F5.9's rule that a blocked send waits.
        ->and($held->state)->toBe(AutomationState::Pending)
        ->and($held->error)->toContain('Sandbox mode is on')
        ->and(MessageDelivery::query()->count())->toBe(0);
});

it('moves a delivery forward and refuses to move it back', function (): void {
    /*
     * SNS delivers at least once and in **no guaranteed order**, so a Delivery
     * notification can arrive after an Open and a duplicate of either an hour
     * later. Ranking is what makes every replay a no-op without a ledger of
     * notification ids — and it is what stops a late "delivered to the server"
     * overwriting a bounce, which is the fact somebody needs.
     */
    $delivery = MessageDelivery::factory()->create([
        'team_id' => $this->team->getKey(),
        'action_instance_id' => sendable([])->getKey(),
    ]);

    expect($delivery->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550'))->toBeTrue()
        // Out of order and later: recorded as a fact, never as the status.
        ->and($delivery->advanceTo(DeliveryStatus::Delivered, now(), 'accepted'))->toBeTrue()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced)
        ->and($delivery->fresh()->detail)->toBe('smtp; 550')
        ->and($delivery->fresh()->delivered_at)->not->toBeNull()
        // And an exact duplicate changes nothing at all.
        ->and($delivery->fresh()->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550'))->toBeFalse();
});

it('stamps when we learned of a failure, not when the provider says it happened', function (): void {
    /*
     * The alert sweep windows on `noticed_at`, and this is why the column
     * exists. A bounce notification routinely arrives minutes after the event
     * and can arrive hours later; a sweep windowing on Amazon's timestamp
     * would find a genuine bounce already behind its high-water mark and
     * never mention it.
     */
    $delivery = MessageDelivery::factory()->create([
        'team_id' => $this->team->getKey(),
        'action_instance_id' => sendable([])->getKey(),
    ]);

    $longAgo = now()->subHours(6);

    $delivery->advanceTo(DeliveryStatus::Bounced, $longAgo, 'smtp; 550');

    $fresh = $delivery->fresh();

    expect($fresh->bounced_at->toIso8601String())->toBe($longAgo->toIso8601String())
        ->and($fresh->noticed_at->greaterThan($longAgo))->toBeTrue();
});

it('records a suppression once, however many times the bounce arrives', function (): void {
    $suppression = app(App\Support\Delivery\Suppression::class);

    expect($suppression->record('dana@example.test', SuppressionReason::HardBounce))->toBeTrue()
        ->and($suppression->record('DANA@Example.test', SuppressionReason::HardBounce))->toBeFalse()
        ->and(SuppressedAddress::query()->count())->toBe(1);
});

it('upgrades a bounce to a complaint but never the other way', function (): void {
    /*
     * A complaint is the more serious fact and governs what a person is told:
     * *"their mailbox is gone"* and *"they reported you"* need different
     * sentences and different conversations.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record('dana@example.test', SuppressionReason::HardBounce);

    expect($suppression->record('dana@example.test', SuppressionReason::Complaint))->toBeTrue()
        ->and(SuppressedAddress::suppresses('dana@example.test'))->toBe(SuppressionReason::Complaint)
        // And back the other way changes nothing.
        ->and($suppression->record('dana@example.test', SuppressionReason::HardBounce))->toBeFalse()
        ->and(SuppressedAddress::suppresses('dana@example.test'))->toBe(SuppressionReason::Complaint);
});

it('survives the team that discovered it being purged', function (): void {
    /*
     * Issue #57 asks the question and this is the answer: the address is still
     * dead after the team has gone, and a purge that resurrected it would hand
     * the account's reputation straight back to the same bounce. Having no
     * `team_id` is what makes this true by construction rather than by an
     * exception in the sweep.
     */
    [$doomed] = $this->teamWithMember();

    SuppressedAddress::factory()->create([
        'email' => 'dana@example.test',
        'discovered_by_team_id' => $doomed->getKey(),
    ]);

    $doomed->forceDelete();

    $row = SuppressedAddress::query()->where('email', 'dana@example.test')->sole();

    expect($row->reason)->toBe(SuppressionReason::HardBounce)
        // The pointer goes; the fact stays.
        ->and($row->discovered_by_team_id)->toBeNull();
});

it('puts a bounce on the deal’s timeline, not only in an alert', function (): void {
    /*
     * ADR 0003's second door. An alert can be missed, filtered, or sent to
     * somebody who has left — and *"has the client been told?"* is asked on
     * the deal months later. A bounce that exists only in an inbox is a bounce
     * nobody can look up.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $delivery->provider_message_id],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [
                    ['emailAddress' => 'dana@example.test', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown'],
                ],
            ],
        ]),
    );

    $event = ActivityEvent::query()->where('event_type', 'message.bounced')->sole();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced)
        ->and($event->deal_id)->toBe($this->deal->getKey())
        ->and($event->summary)->toContain('could not be delivered')
        ->and($event->summary)->toContain('Nothing further will be sent')
        // And the address itself is suppressed for everybody.
        ->and(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);
});

it('does not suppress on a transient bounce', function (): void {
    /*
     * A `Transient` bounce is a full mailbox or a greylist and the address is
     * fine. Suppressing on one would cut a client off for good because their
     * inbox was full on Tuesday — the delivery row still records it, because a
     * team asking whether the disclosure arrived needs the answer either way.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $delivery->provider_message_id],
            'bounce' => [
                'bounceType' => 'Transient',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
            ],
        ]),
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced)
        ->and(SuppressedAddress::suppresses('dana@example.test'))->toBeNull();
});

it('applies an event only to the addresses it names', function (): void {
    /*
     * A bounce for one of three recipients is a bounce for one of them. The
     * notification names its own recipients; applying it to every copy of the
     * message would turn one dead mailbox into three clients recorded as
     * unreachable.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
        ['name' => 'Sam Reilly', 'email' => 'sam@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $id = MessageDelivery::query()->first()->provider_message_id;

    app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $id],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
            ],
        ]),
    );

    $byEmail = MessageDelivery::query()->get()->keyBy('recipient_email');

    expect($byEmail['dana@example.test']->status)->toBe(DeliveryStatus::Bounced)
        ->and($byEmail['sam@example.test']->status)->toBe(DeliveryStatus::Sent)
        ->and(SuppressedAddress::suppresses('sam@example.test'))->toBeNull();
});

it('applies a notification that names nobody to nobody', function (): void {
    /*
     * *"Applies to nobody"* and *"applies to everybody"* are one typo apart,
     * and the safe reading of a truncated notification is the first. A
     * well-formed SES payload always names its recipients; this is about the
     * one that does not.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    $changed = app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $delivery->provider_message_id],
            'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => []],
        ]),
    );

    expect($changed)->toBe(0)
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('reads an event-publishing payload as well as a notification one', function (): void {
    /*
     * Two shapes, and conflating them is the commonest way this integration is
     * got wrong. The older SNS form keys on `notificationType`; **event
     * publishing** keys on `eventType` and is the only one that carries
     * `Open`. An account configured for events sends no `notificationType` at
     * all, so a handler reading only the first records nothing while looking
     * completely healthy.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'eventType' => 'Open',
            'mail' => [
                'messageId' => $delivery->provider_message_id,
                'destination' => ['dana@example.test'],
            ],
            'open' => ['timestamp' => now()->toIso8601String()],
        ]),
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Opened)
        ->and($delivery->fresh()->opened_at)->not->toBeNull();
});

it('suppresses on an escalating bounce even though the row does not move', function (): void {
    /*
     * Round 1 of review. Suppression used to be gated on `advanceTo()`
     * returning true, so a `Transient` bounce followed by a `Permanent` one
     * for the same message and recipient left the address writable: the row
     * was already `bounced`, so nothing advanced, `apply()` returned 0, and
     * the webhook answered 200.
     *
     * The two questions are different. *"Has this row changed?"* is about one
     * message; *"is this mailbox dead?"* is about an address every team on the
     * platform writes to.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    $bounce = fn (string $type): int => app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $delivery->provider_message_id],
            'bounce' => [
                'bounceType' => $type,
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
            ],
        ]),
    );

    $bounce('Transient');

    expect(SuppressedAddress::suppresses('dana@example.test'))->toBeNull();

    // The row is already `bounced`, so this changes nothing about the message
    // — and everything about the address.
    expect($bounce('Permanent'))->toBe(1)
        ->and(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);
});

it('records a bounce for a team inside its purge window', function (): void {
    /*
     * Round 1 of review. `Team` soft-deletes, so a team in its 30-day window
     * came back null and the whole notification was dropped: no suppression,
     * no timeline, a 200 back to Amazon — and the address stayed writable for
     * every other team on the platform, which is the one thing this table
     * exists to prevent. A tenant's lifecycle must not decide a fact that is
     * not the tenant's.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    $this->team->delete();

    app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $delivery->provider_message_id],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
            ],
        ]),
    );

    expect(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);
});

it('lets only one of two concurrent notifications write the timeline entry', function (): void {
    /*
     * Round 1 of review. SNS delivers at least once, so two copies of one
     * bounce can land on two web workers at the same moment. Both read a row
     * still marked `sent`, both decided they had changed it, and the deal got
     * **two** `message.bounced` entries — the unique index protected the
     * suppression, and nothing protected the timeline.
     *
     * Two separately-loaded models is what a second worker actually holds: it
     * deserialised its own copy before the first one wrote. `->fresh()` would
     * re-read after the write and prove nothing.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $id = MessageDelivery::query()->sole()->getKey();

    $first = MessageDelivery::query()->findOrFail($id);
    $second = MessageDelivery::query()->findOrFail($id);

    expect($first->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550'))->toBeTrue()
        // The loser stands down, exactly as a replay does.
        ->and($second->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550'))->toBeFalse();

    /*
     * And **the entry itself**, which round 2 of review pointed out this test
     * did not look at: asserting `true`/`false` from `advanceTo()` would pass
     * unchanged against an implementation that wrote two timeline rows, which
     * is the thing the test's name claims to hold.
     */
    $event = fn (): int => ActivityEvent::query()->where('event_type', 'message.bounced')->count();

    $apply = fn (MessageDelivery $row): int => app(App\Support\Delivery\ApplyDeliveryEvent::class)->apply(
        App\Support\Delivery\DeliveryEvent::tryFrom([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $row->provider_message_id],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
            ],
        ]),
    );

    $fresh = MessageDelivery::query()->findOrFail($id);

    $apply($fresh);
    $apply($fresh);

    expect($event())->toBe(1);
});

it('speaks about the address rather than about the reader', function (): void {
    /*
     * Round 1 of review, and the one that contradicted a rule this PR added to
     * ADR 0002 in the same breath. The complaint explanation said *"somebody
     * at this address marked a message **from you** as spam … continuing to
     * write to somebody who has reported **you**"* — rendered verbatim to a
     * team that had nothing to do with the complaint. False for them, and a
     * disclosure that a shared contact filed a complaint over another team's
     * mail.
     *
     * The old test asserted only that the other team's *name* was absent,
     * which cannot see a sentence that discloses the fact without naming
     * anybody — and its fixture took the factory default (`hard_bounce`),
     * whose wording was already address-only, so the complaint string was
     * rendered by no test at all.
     */
    [$otherTeam] = $this->teamWithMember();

    SuppressedAddress::factory()->complaint()->create([
        'email' => 'dana@example.test',
        'discovered_by_team_id' => $otherTeam->getKey(),
    ]);

    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $error = (string) $instance->fresh()->error;

    expect($error)->toContain('reported as receiving unwanted mail')
        ->and($error)->not->toContain('from you')
        ->and($error)->not->toContain('reported you')
        ->and($error)->not->toContain('your team')
        ->and($error)->not->toContain($otherTeam->name);
});

it('does not let a replayed bounce reverse an operator’s lift', function (): void {
    /*
     * Round 3 of review, and the third round running in which a fix opened
     * the next defect — this one in the restore branch added for round 2's
     * lift-audit blocker.
     *
     * SNS retries a failed delivery for up to 23 days and duplicates arrive
     * after a successful one, so a replayed copy of *the very notification
     * that caused the suppression* landed after an operator had lifted it, and
     * restored the row. The sequence is the natural one rather than a
     * coincidence: an operator lifts **because** of the bounce, so the lift
     * lands inside the retry window.
     *
     * Two consequences, both measured by the reviewer: the only escape hatch
     * from a permanent, account-wide block undid itself with nothing anywhere
     * recording that it had, and the deal got a **second** `message.bounced`
     * entry — round 1's duplicate arriving through the fix for round 2's.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $delivery = MessageDelivery::query()->sole();

    $bouncedAt = now()->subMinutes(10);

    $notification = [
        'notificationType' => 'Bounce',
        'mail' => ['messageId' => $delivery->provider_message_id],
        'bounce' => [
            'bounceType' => 'Permanent',
            // The provider's clock, fixed: a replay carries the timestamp of
            // the original event, which is the whole discriminator.
            'timestamp' => $bouncedAt->toIso8601String(),
            'bouncedRecipients' => [['emailAddress' => 'dana@example.test']],
        ],
    ];

    $apply = fn (): int => app(App\Support\Delivery\ApplyDeliveryEvent::class)
        ->apply(App\Support\Delivery\DeliveryEvent::tryFrom($notification));

    $apply();

    expect(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);

    // The operator decides the mailbox is fine after all.
    app(App\Support\Delivery\Suppression::class)->lift('dana@example.test');

    expect(SuppressedAddress::suppresses('dana@example.test'))->toBeNull();

    // And the provider retries the same notification.
    expect($apply())->toBe(0)
        ->and(SuppressedAddress::suppresses('dana@example.test'))->toBeNull()
        // One entry, not two.
        ->and(ActivityEvent::query()->where('event_type', 'message.bounced')->count())->toBe(1);
});

it('lets a genuinely new bounce re-suppress after a lift', function (): void {
    /*
     * The other side of the same gate: a lift is not permanent immunity. A
     * bounce that happened **after** the operator's decision is new
     * information about the address and suppresses it again.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record('dana@example.test', SuppressionReason::HardBounce, occurredAt: now()->subHour());
    $suppression->lift('dana@example.test');

    expect($suppression->record('dana@example.test', SuppressionReason::HardBounce, occurredAt: now()))
        ->toBeTrue()
        ->and(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);
});

it('keeps the more serious reason across a lift', function (): void {
    /*
     * `Suppression`'s class docblock states the invariant — *"an address
     * suppressed for a complaint and later hard-bouncing stays recorded as a
     * complaint, because that is the more serious fact and the one that
     * governs what a person is told"* — and the restore branch overwrote it
     * unconditionally. Complaint → lift → hard bounce left the row reading
     * `hard_bounce`, so a team was told to *"check the address with them"*
     * about somebody who had reported them for spam, and the complaint
     * vanished from exactly the history the soft delete exists to preserve.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record('dana@example.test', SuppressionReason::Complaint, occurredAt: now()->subDay());
    $suppression->lift('dana@example.test');

    $suppression->record('dana@example.test', SuppressionReason::HardBounce, occurredAt: now());

    expect(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::Complaint);
});

it('writes a delivery confirmation past a bounce, even from a stale worker', function (): void {
    /*
     * Round 3 of review: round 2's two-branch predicate promised to keep a
     * late "delivered to the server" notification writing `delivered_at` on
     * the way past a row that has since bounced, and did it only for a
     * **fresh** read. A stale worker computes `$advancing` against the status
     * it read, takes the rank branch, and misses — which is round 1's
     * `WHERE status = ` defect surviving inside its own fix.
     *
     * Two separately-loaded models is what two workers actually hold; round
     * 2's test used `->fresh()`, so it could not see the case the predicate
     * was rewritten for.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $id = MessageDelivery::query()->sole()->getKey();

    $stale = MessageDelivery::query()->findOrFail($id);
    $bounceWorker = MessageDelivery::query()->findOrFail($id);

    $bounceWorker->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550');

    // Still holding `sent`, and its fact still lands.
    expect($stale->advanceTo(DeliveryStatus::Delivered, now()->subMinute()))->toBeTrue();

    $final = MessageDelivery::query()->findOrFail($id);

    expect($final->status)->toBe(DeliveryStatus::Bounced)
        ->and($final->delivered_at)->not->toBeNull()
        ->and($final->detail)->toBe('smtp; 550');
});

it('lets a complaint through the lift gate even when its clock predates the lift', function (): void {
    /*
     * Round 4 of review, and the fourth round running in which a fix opened
     * the next defect. Round 3's gate — *"did this happen after somebody
     * decided the address was fine"* — is right about a **replay** of the
     * notification the operator lifted in response to, and wrong about an
     * event of a different **kind**.
     *
     * And it is wrong essentially always, not rarely: while an address is
     * suppressed nothing goes out, so any complaint in flight is necessarily
     * about a message sent *before* the suppression, and feedback loops run
     * minutes to hours behind the timestamp they carry. The reviewer measured
     * the consequence end to end — the address stayed writable, the
     * `message.complained` entry lost its "nothing further will be sent"
     * clause, and the next automated message went out to the person who had
     * reported the team.
     *
     * PRD §12.2 measures complaints per **account** at 0.1%, so that is every
     * tenant's deliverability.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record('dana@example.test', SuppressionReason::HardBounce, occurredAt: now()->subHours(5));
    $suppression->lift('dana@example.test');

    // The ISP's own clock, well before the operator's decision.
    expect($suppression->record('dana@example.test', SuppressionReason::Complaint, occurredAt: now()->subHours(4)))
        ->toBeTrue()
        ->and(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::Complaint);
});

it('still refuses a replayed event of the same kind after a lift', function (): void {
    /*
     * The other side of the escalation clause: it must let the categorically
     * new fact through without reopening round 3's blocker. A hard bounce
     * replayed against a hard-bounce row does not outrank it, and neither does
     * a complaint against a complaint.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    foreach ([SuppressionReason::HardBounce, SuppressionReason::Complaint] as $reason) {
        $email = $reason->value.'@example.test';

        $suppression->record($email, $reason, occurredAt: now()->subHours(5));
        $suppression->lift($email);

        expect($suppression->record($email, $reason, occurredAt: now()->subHours(5)))->toBeFalse()
            ->and(SuppressedAddress::suppresses($email))->toBeNull();
    }
});

it('keeps the reason and the words beside it together across a lift', function (): void {
    /*
     * Round 4 of review, a half-fix one column along from round 3's. Round 3
     * kept the stronger `reason` on restore and overwrote `detail` and
     * `discovered_by_team_id` unconditionally, so an operator's row read
     * *"Marked as spam"* over *"smtp; 550 5.1.1 user unknown"* — the
     * team-facing sentence right, the row contradicting itself.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record(
        'dana@example.test',
        SuppressionReason::Complaint,
        detail: 'abuse report from the receiving provider',
        occurredAt: now()->subDay(),
    );

    $suppression->lift('dana@example.test');

    $suppression->record(
        'dana@example.test',
        SuppressionReason::HardBounce,
        detail: 'smtp; 550 5.1.1 user unknown',
        occurredAt: now(),
    );

    $row = SuppressedAddress::query()->where('email', 'dana@example.test')->sole();

    expect($row->reason)->toBe(SuppressionReason::Complaint)
        ->and($row->detail)->toBe('abuse report from the receiving provider');
});

it('does not leave a stale worker claiming a status the row disagrees with', function (): void {
    /*
     * Round 4 of review. The fallback added in round 3 returns `true` from a
     * model that is stale by construction — that is what put it on that branch
     * — and `forceFill(...)->syncOriginal()` then stamped the status this
     * worker *read* as the original. A caller reading `$delivery->status`
     * after a `true` got a value the database disagrees with, with
     * `isDirty()` saying everything was fine.
     */
    $instance = sendable([
        ['name' => 'Dana Okafor', 'email' => 'dana@example.test', 'membershipId' => null],
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team);

    $id = MessageDelivery::query()->sole()->getKey();

    $stale = MessageDelivery::query()->findOrFail($id);

    MessageDelivery::query()->findOrFail($id)->advanceTo(DeliveryStatus::Bounced, now(), 'smtp; 550');

    expect($stale->advanceTo(DeliveryStatus::Delivered, now()))->toBeTrue()
        // Re-read, so what it reports is what the row says.
        ->and($stale->status)->toBe(DeliveryStatus::Bounced)
        ->and($stale->delivered_at)->not->toBeNull()
        ->and($stale->isDirty())->toBeFalse();
});
