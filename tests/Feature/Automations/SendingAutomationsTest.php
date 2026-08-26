<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Enums\TaskSource;
use App\Mail\AutomatedMessageMail;
use App\Models\ActionInstance;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Task;
use App\Support\Automation\ExecuteAction;
use App\Support\Automation\SendRails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * F5.9's three rails and the send path behind them (PRD §4.5 · #92, #96).
 *
 * PRD §4.5 calls these **launch blockers, not enhancements**, and issue #96
 * says where they have to live: *"at the send path, in the queue worker, not
 * in the UI. Every one of them must hold when a message is sent by a scheduled
 * job at 3am with no human present."* So every test here goes through
 * `ExecuteAction`, which is what a worker calls, and never through a screen.
 */
beforeEach(function (): void {
    Mail::fake();

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

function pendingMessage(array $attributes = []): ActionInstance
{
    return ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);
}

function carryOut(ActionInstance $instance): ActionInstance
{
    app(ExecuteAction::class)->handle($instance, test()->team);

    return $instance->fresh();
}

it('sends a complete message and records that it went', function (): void {
    $instance = carryOut(pendingMessage());

    Mail::assertSent(AutomatedMessageMail::class, fn (AutomatedMessageMail $mail): bool => $mail->hasTo('dana@example.test'));

    expect($instance->state)->toBe(AutomationState::Sent)
        ->and($instance->executed_at)->not->toBeNull()
        ->and($instance->message_key)->not->toBeNull();
});

it('writes the idempotency key before the transport is called', function (): void {
    /*
     * The whole guarantee, asserted at the one instant it is observable. A
     * provider call can time out **after** the provider accepted the message,
     * so an id handed back by the provider is exactly what a timed-out send
     * does not have.
     */
    $instance = pendingMessage();

    expect($instance->message_key)->toBeNull();

    $keyAtSendTime = null;

    app(ExecuteAction::class)->handle($instance, $this->team);

    /*
     * Read off the mailable, which is the instance as it stood at the moment
     * it was handed over. Asserting on the row afterwards would prove only
     * that the key exists *eventually*, which is the thing that is true either
     * way round — and the wrong order is the one that sends a client the same
     * message twice.
     */
    Mail::assertSent(AutomatedMessageMail::class, function (AutomatedMessageMail $mail) use (&$keyAtSendTime): bool {
        $keyAtSendTime = $mail->instance->message_key;

        return true;
    });

    expect($keyAtSendTime)->not->toBeNull()
        ->and($keyAtSendTime)->toBe($instance->fresh()->message_key);
});

it('never sends the same message twice', function (): void {
    $instance = pendingMessage();

    carryOut($instance);
    Mail::assertSentCount(1);

    /*
     * A worker picking the row up again — a queue visibility timeout, a
     * retried job, the scheduler's sweep. The state alone would not stop it
     * in the case that matters: a send that threw leaves `failed` and a key.
     */
    $instance->fresh()->forceFill(['state' => AutomationState::Pending->value])->save();

    carryOut($instance->fresh());

    Mail::assertSentCount(1);
});

it('halts rather than refuses when the team’s kill switch is on', function (): void {
    /*
     * F5.8: *"when a team calls to say stop, something is wrong, the answer
     * must be one toggle, and it must catch everything already queued."* A
     * halted message stays `pending` — it is paused, not cancelled — so
     * lifting the switch releases it.
     */
    $this->team->forceFill([
        'sends_disabled_at' => now(),
        'sends_disabled_reason' => 'The team called and asked us to stop.',
    ])->save();

    $instance = carryOut(pendingMessage());

    Mail::assertNothingSent();

    expect($instance->state)->toBe(AutomationState::Pending)
        ->and($instance->error)->toBe('The team called and asked us to stop.')
        ->and($instance->message_key)->toBeNull();
});

it('reads the kill switch live rather than from the team it was handed', function (): void {
    /*
     * The point of this rail is that it takes effect *now*. A worker holding a
     * `Team` from thirty seconds ago would keep sending after the toggle, and
     * thirty seconds is a lot of messages.
     */
    $stale = $this->team->replicate();
    $stale->id = $this->team->getKey();
    $stale->exists = true;

    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    app(ExecuteAction::class)->handle($instance = pendingMessage(), $stale);

    Mail::assertNothingSent();
    expect($instance->fresh()->state)->toBe(AutomationState::Pending);
});

it('refuses a message with an unfilled merge field', function (): void {
    /*
     * #93: *"a missing merge field blocks approval"* — and blocks a send, too,
     * because an automation that fires without a human never reached the
     * approval queue at all. A refusal rather than a halt: waiting will not
     * make it fillable.
     */
    $instance = carryOut(pendingMessage([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'unresolved' => ['property_address'],
        ],
    ]));

    Mail::assertNothingSent();

    expect($instance->state)->toBe(AutomationState::Failed)
        ->and($instance->error)->toContain('property_address');
});

it('refuses a message with a dropped brace', function (): void {
    // The defect PR #175's review found, at the last gate before a transport:
    // `{{ client_name }` survives substitution and reaches the client as the
    // template's own internals.
    $instance = carryOut(pendingMessage([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'malformed' => ['{{'],
        ],
    ]));

    Mail::assertNothingSent();
    expect($instance->state)->toBe(AutomationState::Failed);
});

it('refuses a message that resolves to nobody', function (): void {
    /*
     * PRD §1.1's second question is *"has the client been told?"* and silence
     * is the answer this product must never give. A send that reaches nobody
     * is a failure with a reason, not a success with an empty list.
     */
    $instance = carryOut(pendingMessage([
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'recipients' => [],
        ],
    ]));

    Mail::assertNothingSent();

    expect($instance->state)->toBe(AutomationState::Failed)
        ->and($instance->error)->toContain('resolved to nobody');
});

it('halts when the team has hit its hourly ceiling', function (): void {
    /*
     * F5.9: exceeding the limit *"halts sending and alerts — it does not
     * silently drop"*. So the row stays `pending` and the sweep picks it up
     * once the rolling window moves.
     */
    $this->team->forceFill(['hourly_send_limit' => 2])->save();

    ActionInstance::factory()->count(2)->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $instance = carryOut(pendingMessage());

    Mail::assertNothingSent();

    expect($instance->state)->toBe(AutomationState::Pending)
        ->and($instance->error)->toContain('limit of messages for the hour')
        /*
         * **Zero**, not one. `attempts` counts tries at the transport and a
         * halt never reached one — the sweep re-dispatches a halted message
         * every minute, so counting halts overflowed the `smallint` column
         * after about three weeks with the kill switch on and turned a paused
         * queue into a throwing one.
         */
        ->and($instance->attempts)->toBe(0);
});

it('does not count a task or a manual prompt against the email ceiling', function (): void {
    /*
     * F5.9's ceiling exists so a looping automation cannot reach clients four
     * hundred times, and a created task reaches nobody. Counting every `sent`
     * row meant three `create_task` automations across a busy morning silently
     * paused the team's actual client email — the ceiling causing the failure
     * it exists to prevent.
     */
    $this->team->forceFill(['hourly_send_limit' => 2])->save();

    ActionInstance::factory()->count(2)->creatingATask()->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    carryOut(pendingMessage());

    Mail::assertSentCount(1);
});

it('counts the ceiling on a rolling window rather than a calendar hour', function (): void {
    // An hourly limit that resets on the hour lets a loop send two hours'
    // worth in two minutes across the boundary.
    $this->team->forceFill(['hourly_send_limit' => 2])->save();

    ActionInstance::factory()->count(2)->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'executed_at' => now()->subMinutes(90),
    ]);

    carryOut(pendingMessage());

    Mail::assertSentCount(1);
});

it('counts only this team’s sends against its ceiling', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $this->team->forceFill(['hourly_send_limit' => 1])->save();

    app(App\Support\Tenancy\TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): void {
        ActionInstance::factory()->sent()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => Deal::factory()->create(['team_id' => $otherTeam->getKey()])->getKey(),
        ]);
    });

    $this->withTeam($this->team);

    carryOut(pendingMessage());

    Mail::assertSentCount(1);
});

it('redirects to the team owner in sandbox mode rather than refusing', function (): void {
    /*
     * The third rail rewrites rather than refuses, and so runs last — a
     * redirected message still counts against the ceiling, because a loop that
     * sends four hundred messages to the team owner is still a loop.
     */
    $this->team->forceFill(['sandbox_mode' => true])->save();

    $instance = carryOut(pendingMessage());

    Mail::assertSent(AutomatedMessageMail::class, fn (AutomatedMessageMail $mail): bool => ! $mail->hasTo('dana@example.test') && $mail->redirected);

    expect($instance->state)->toBe(AutomationState::Sent);
});

it('says on the deal that a sandboxed message did not reach the client', function (): void {
    // An owner holding a message that looks exactly like the real one is an
    // owner who forwards it to the client believing the product already did.
    $this->team->forceFill(['sandbox_mode' => true])->save();

    carryOut(pendingMessage());

    expect(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->pluck('event_type')->all())
        ->toContain('message.redirected');
});

it('puts a failure on the deal’s own timeline rather than only in a log', function (): void {
    carryOut(pendingMessage([
        'payload' => [...ActionInstance::factory()->definition()['payload'], 'recipients' => []],
    ]));

    expect(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->pluck('event_type')->all())
        ->toContain('message.failed');
});

it('writes no recipient address into the audit log', function (): void {
    /*
     * PRD §9: no PII in logs, ever. `AuditRedactor` exists to make that
     * unskippable, and a key spelled to slip past it would defeat the
     * mechanism rather than use it — the addresses live on the instance this
     * entry is `auditable` against, under its own access control.
     */
    $instance = carryOut(pendingMessage());

    $entry = AuditEntry::query()->where('action', 'message.sent')->sole();

    expect(json_encode($entry->after))->not->toContain('dana@example.test')
        ->and($entry->after['recipient_count'])->toBe(1)
        ->and($entry->after['message_key'])->toBe($instance->message_key);
});

it('creates a task from a task automation without the rails', function (): void {
    // A task reaches nobody outside the team, so the rails — which are about
    // messages leaving the building — do not apply.
    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    $instance = carryOut(pendingMessage(
        ActionInstance::factory()->creatingATask('Order the survey')->raw(['deal_id' => $this->deal->getKey()]),
    ));

    $task = Task::query()->sole();

    expect($instance->state)->toBe(AutomationState::Sent)
        ->and($task->title)->toBe('Order the survey')
        ->and($task->source)->toBe(TaskSource::Automation)
        /*
         * **Never required.** `required_tasks_complete` counts the required
         * tasks on one stage, so a required task raised by a `stage_start`
         * automation on that same stage is a gate the team did not write,
         * blocking a stage they just entered (#69's finding, one enum over).
         */
        ->and($task->is_required)->toBeFalse();
});

it('refuses a manual prompt reaching the queue', function (): void {
    // F5.4's manual action is done by a person and recorded by ApproveMessage;
    // a worker cannot do somebody's job for them.
    $instance = carryOut(pendingMessage(['action_type' => 'manual_prompt']));

    expect($instance->state)->toBe(AutomationState::Failed)
        ->and($instance->error)->toContain('marked done by a person');
});

it('stands down rather than failing an instance that is waiting for approval', function (): void {
    /*
     * A row in any state but `pending` belongs to whoever put it there, and a
     * worker arriving late must write **nothing** — not the state, not the
     * error, not a timeline entry. Marking it `failed` would take a message
     * sitting in the approval queue and put it permanently beyond approving.
     */
    $instance = carryOut(pendingMessage(['state' => AutomationState::AwaitingApproval]));

    Mail::assertNothingSent();

    expect($instance->state)->toBe(AutomationState::AwaitingApproval)
        ->and($instance->error)->toBeNull()
        ->and(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0);
});

it('stands down rather than overwriting a message somebody stopped', function (): void {
    /*
     * The defect round 1 found, and the ordinary sequence rather than a race:
     * `ApproveMessage::cancel()` deliberately allows stopping a **pending**
     * instance, and a pending instance is one that has already been
     * dispatched. So "queued → somebody presses Stop → the worker arrives"
     * happens every time somebody uses the feature.
     *
     * Three things went wrong when this refused instead of standing down. The
     * reason a person typed was destroyed; the deal's timeline carried *"an
     * automated message did not go out: this message is Cancelled"*, which
     * contradicts itself; and — worst — the row flipped from `cancelled` to
     * `failed`, where `RaiseAutomations::alreadyRaised()` counts it. A skipped
     * stage that was later reopened then silently never re-raised its message,
     * which is the contract this whole feature is built around.
     */
    $instance = pendingMessage();

    $instance->forceFill([
        'state' => AutomationState::Cancelled->value,
        'error' => 'The buyer called; do not send.',
    ])->save();

    carryOut($instance);

    Mail::assertNothingSent();

    expect($instance->fresh()->state)->toBe(AutomationState::Cancelled)
        ->and($instance->fresh()->error)->toBe('The buyer called; do not send.')
        ->and(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0);
});

it('refuses a message belonging to another team', function (): void {
    /*
     * Hardening rather than a live hole — `RunAutomation` re-establishes the
     * team and finds the row inside that scope. It is here because the
     * consequence of a future mismatched caller is specific and silent: team
     * A's sandbox setting redirecting team B's client message to team A's
     * owner, and team A's ceiling pausing team B's sends.
     */
    [$otherTeam] = $this->teamWithMember();

    $instance = pendingMessage();

    $decision = app(SendRails::class)->decide($instance, $otherTeam);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->ownedByAnother)->toBeTrue()
        ->and($decision->reason)->toContain('does not belong to the team');
});

it('logs a ceiling breach without naming anybody', function (): void {
    /*
     * PRD §9: no PII in logs, ever. The first version of this test asserted
     * only that the decision was a halt — so an `alert()` that interpolated
     * the recipient's name into the message would have passed it, which is
     * the assertion measuring the wrong half of the behaviour it is named for.
     */
    Log::spy();

    // One below the limit rather than a limit of zero: `SendSafetyController`
    // validates `between:1,500`, so a zero-limit fixture is a state the
    // product cannot be in and a test written against one proves nothing.
    $this->team->forceFill(['hourly_send_limit' => 1])->save();

    ActionInstance::factory()->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $decision = app(SendRails::class)->decide(pendingMessage(), $this->team);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->retryable)->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(
        function (string $message, array $context): bool {
            expect($message)->toBe('Team reached its outbound message ceiling.')
                // The whole context, by key: a new key carrying a name or an
                // address fails here rather than being waved through by an
                // assertion that only looked at the ones it knew about.
                ->and(array_keys($context))->toBe(['team_id', 'window', 'sent', 'limit'])
                ->and(json_encode($context))->not->toContain('dana@example.test')
                ->and(json_encode($context))->not->toContain('Dana');

            return true;
        },
    );
});

it('never narrates an outcome for a claim it does not own', function (): void {
    /*
     * Three rounds of review went into this one word, and the answer is
     * **stand down, always**.
     *
     * A `pending` row carrying a `message_key` does not mean the worker died.
     * It means *some* worker claimed it and has not written its outcome, and
     * the commonest reading is a sibling inside `Mail::send` at this instant —
     * two workers on one row is what a queue does after a visibility timeout,
     * which is the thing the claim exists for.
     *
     * There is no signal here that separates them. `updated_at` looked like
     * one and is not: the second delivery happens *because* the claim aged
     * past the visibility timeout, so the crashed worker and the live sibling
     * are the same age at the only moment a worker looks. A threshold below it
     * calls live sends failures; a threshold above it is never reached,
     * because standing down completes the job and there is no third delivery.
     *
     * So the outcome is decided away from the claim, by
     * `automations:reap-unconfirmed`, and the row is *visible* on S47 in the
     * meantime — a read, which cannot contradict anybody.
     */
    $instance = pendingMessage();

    ActionInstance::query()->whereKey($instance->getKey())->update([
        'message_key' => (string) Str::ulid(),
        // Old enough that any staleness threshold would have fired.
        'updated_at' => now()->subHour(),
    ]);

    carryOut($instance->fresh());

    Mail::assertNothingSent();

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->error)->toBeNull()
        ->and($instance->fresh()->executed_at)->toBeNull()
        ->and(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0);
});

it('records the outcome of an unconfirmed send from a distance', function (): void {
    /*
     * The other end of the same problem. Hours later there is no live sibling
     * to contradict: a send that has not written its outcome by then is not
     * going to. The sentence is deliberately not a claim in either direction,
     * because nobody knows — and a person deciding whether to resend needs to
     * be told that rather than reassured.
     */
    $instance = pendingMessage();

    ActionInstance::query()->whereKey($instance->getKey())->update([
        'message_key' => (string) Str::ulid(),
        'updated_at' => now()->subDay(),
    ]);

    $this->artisan('automations:reap-unconfirmed')->assertSuccessful();

    expect($instance->fresh()->state)->toBe(AutomationState::Failed)
        ->and($instance->fresh()->error)->toContain('never confirmed')
        ->and($instance->fresh()->error)->toContain('may have reached the recipient');

    expect(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->pluck('event_type')->all())
        ->toContain('message.failed');
});

it('leaves a claim the reaper is not yet sure about', function (): void {
    // The cost of waiting is a row on S47 saying it is unconfirmed, which is
    // true. The cost of being hasty is telling a team a message failed while
    // it is being delivered — the failure this command exists because of.
    $instance = pendingMessage();

    ActionInstance::query()->whereKey($instance->getKey())->update([
        'message_key' => (string) Str::ulid(),
        'updated_at' => now()->subMinutes(20),
    ]);

    $this->artisan('automations:reap-unconfirmed');

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->error)->toBeNull();
});

it('does not narrate a cancelled message when the kill switch is on', function (): void {
    /*
     * The ordering round 4 found: every rail below can *write* to the row —
     * a halt stamps `error` — so asking the kill switch before the state gate
     * meant a worker arriving late at a stopped message overwrote the reason a
     * person typed with the rail's own sentence. Round 1's finding #2 again,
     * through the one branch left above the gate.
     */
    $instance = pendingMessage();

    $instance->forceFill([
        'state' => AutomationState::Cancelled->value,
        'error' => 'The buyer called; do not send.',
    ])->save();

    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    carryOut($instance);

    expect($instance->fresh()->state)->toBe(AutomationState::Cancelled)
        ->and($instance->fresh()->error)->toBe('The buyer called; do not send.');
});

it('does not write a rail error onto a message that was sent', function (): void {
    $instance = ActionInstance::factory()->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    carryOut($instance);

    expect($instance->fresh()->state)->toBe(AutomationState::Sent)
        ->and($instance->fresh()->error)->toBeNull();
});

it('still stands down on the retry after a transport threw', function (): void {
    /*
     * The other half of the same branch, and the reason it is a branch: a row
     * that is already terminal belongs to whoever put it there. `ExecuteAction`
     * writes `failed` **before** it rethrows, so the queue's retry finds a
     * non-pending row and must add nothing — three more "did not go out"
     * entries about a message that may well have been delivered is the noise
     * round 1 objected to.
     */
    $instance = pendingMessage();

    $instance->forceFill([
        'message_key' => (string) Str::ulid(),
        'state' => AutomationState::Failed->value,
        'error' => 'The mail transport rejected this message (RuntimeException).',
        'executed_at' => now(),
    ])->save();

    carryOut($instance);

    expect($instance->fresh()->error)->toBe('The mail transport rejected this message (RuntimeException).')
        ->and(ActivityEvent::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0);
});
