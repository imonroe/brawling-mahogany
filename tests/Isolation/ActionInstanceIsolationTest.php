<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Mail\AutomatedMessageMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\TeamMembership;
use App\Support\Automation\ExecuteAction;
use App\Support\Automation\SendRails;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Mail;

/**
 * A message about one team's client never reaches another (ADR 0002 · #92).
 *
 * `action_instances` is the highest-consequence table this suite covers, and
 * the reason is not the row: a leak here is not a screen showing somebody
 * else's data, it is **an email arriving in a stranger's inbox** with a third
 * party's client name, address and transaction in it. PRD §4.5 calls that
 * unrecallable, and it is the one failure in this product that cannot be
 * fixed by deleting a row.
 *
 * So the ordinary five layers, plus the three that are specific to this table:
 * the send path re-reads the team, the rate ceiling counts one team's sends,
 * and the sandbox redirect resolves *this* team's owner.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->teams = collect(['a', 'b'])->mapWithKeys(function (string $key): array {
        [$team] = $this->teamWithMember();

        $owner = app(TeamContext::class)->runFor($team, fn (): TeamMembership => TeamMembership::query()
            ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
            ->sole());

        app(TeamContext::class)->runFor($team, function () use ($team, $owner): void {
            $owner->forceFill(['email' => "owner-{$team->getKey()}@example.test"])->save();

            $team->forceFill([
                'sandbox_mode' => false,
                'approval_required_until' => now()->subDay(),
            ])->save();
        });

        return [$key => [
            'team' => $team,
            'owner' => $owner,
            'deal' => app(TeamContext::class)->runFor(
                $team,
                fn (): Deal => Deal::factory()->create(['team_id' => $team->getKey()]),
            ),
        ]];
    });
});

function messageIn(string $key, array $attributes = []): ActionInstance
{
    $context = test()->teams[$key];

    return app(TeamContext::class)->runFor(
        $context['team'],
        fn (): ActionInstance => ActionInstance::factory()->create([
            'team_id' => $context['team']->getKey(),
            'deal_id' => $context['deal']->getKey(),
            ...$attributes,
        ]),
    );
}

function signedInAs(string $key): void
{
    $context = test()->teams[$key];

    test()->actingAsPerson(test()->enrollTwoFactor($context['owner']->person), $context['team']);
}

it('does not list another team’s queued messages', function (): void {
    messageIn('a', ['state' => AutomationState::AwaitingApproval]);
    messageIn('b', ['state' => AutomationState::AwaitingApproval]);

    signedInAs('a');

    $this->get('/messages')->assertInertia(fn ($page) => $page->has('waiting', 1));
});

it('refuses a direct route to another team’s message', function (): void {
    $theirs = messageIn('b');

    signedInAs('a');

    $this->get("/messages/{$theirs->getKey()}")->assertNotFound();
});

it('refuses to approve another team’s message', function (): void {
    /*
     * The one that matters most on this table. Approving is not reading — it
     * puts a message on a transport — so a 404 here is the difference between
     * a leak and a leak that also emails somebody.
     */
    $theirs = messageIn('b', ['state' => AutomationState::AwaitingApproval]);

    signedInAs('a');

    $this->post("/messages/{$theirs->getKey()}/approval")->assertNotFound();

    expect($theirs->fresh()->state)->toBe(AutomationState::AwaitingApproval);
});

it('refuses to stop another team’s message', function (): void {
    $theirs = messageIn('b', ['state' => AutomationState::AwaitingApproval]);

    signedInAs('a');

    $this->delete("/messages/{$theirs->getKey()}/approval")->assertNotFound();

    expect($theirs->fresh()->state)->toBe(AutomationState::AwaitingApproval);
});

it('counts only the asking team’s messages on the shell badge', function (): void {
    messageIn('a', ['state' => AutomationState::AwaitingApproval]);
    messageIn('b', ['state' => AutomationState::AwaitingApproval]);
    messageIn('b', ['state' => AutomationState::AwaitingApproval]);

    signedInAs('a');

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('counts.pendingMessages', 1));
});

it('counts only one team’s sends against its ceiling', function (): void {
    /*
     * A ceiling that counted every team's sends would let one busy team stop
     * another's client emails — a denial of service across the tenant
     * boundary, delivered as silence.
     */
    $a = $this->teams['a']['team'];

    app(TeamContext::class)->runFor($a, fn () => $a->forceFill(['hourly_send_limit' => 1])->save());

    messageIn('b', ['state' => AutomationState::Sent, 'executed_at' => now(), 'message_key' => 'k']);

    $mine = messageIn('a');

    app(TeamContext::class)->runFor($a, function () use ($mine, $a): void {
        app(ExecuteAction::class)->handle($mine, $a);
    });

    expect($mine->fresh()->state)->toBe(AutomationState::Sent);
});

it('redirects a sandboxed message to its own team’s owner', function (): void {
    /*
     * The sandbox rewrites a recipient, which makes it the one rail that can
     * *cause* a cross-tenant send rather than merely fail to prevent one. If
     * it resolved "an owner" rather than "this team's owner", every sandboxed
     * message on the install would go to whoever came first.
     */
    $a = $this->teams['a']['team'];

    app(TeamContext::class)->runFor($a, fn () => $a->forceFill(['sandbox_mode' => true])->save());

    $mine = messageIn('a');

    app(TeamContext::class)->runFor($a, fn () => app(ExecuteAction::class)->handle($mine, $a));

    Mail::assertSent(
        AutomatedMessageMail::class,
        fn (AutomatedMessageMail $mail): bool => $mail->hasTo("owner-{$a->getKey()}@example.test"),
    );
});

it('will not send a message whose team has been handed in wrong', function (): void {
    /*
     * The rails are handed a `Team` and asked about an `ActionInstance`, and
     * until round 1 nothing checked the two agreed. The consequence of a
     * future mismatched caller is specific and silent: team A's sandbox
     * setting redirecting team B's client message to team A's owner, and team
     * A's ceiling pausing team B's sends.
     *
     * The first version of this test passed team B's instance with team B's
     * team, having disabled sending on team B — a second kill-switch test
     * wearing a cross-tenant title, which is the shape `docs/Testing.md` warns
     * about: it would have passed identically with no check at all.
     */
    $a = $this->teams['a']['team'];

    $theirs = messageIn('b');

    $decision = app(TeamContext::class)->runFor(
        $a,
        fn () => app(SendRails::class)->decide($theirs, $a),
    );

    expect($decision->allowed)->toBeFalse()
        /*
         * A **stand-down**, so nothing is written. A refusal would send
         * `ExecuteAction` into `fail()`, and the guard against touching
         * another tenant's row would answer by writing to another tenant's
         * row — under this team's established scope, at that.
         */
        ->and($decision->ownedByAnother)->toBeTrue()
        ->and($decision->reason)->toContain('does not belong to the team');

    // And the row really is untouched, which is the half the assertion above
    // only implies.
    expect($theirs->fresh()->state)->toBe(AutomationState::Pending)
        ->and($theirs->fresh()->error)->toBeNull()
        ->and($theirs->fresh()->message_key)->toBeNull();

    /*
     * And the control, without which the assertion above passes for any
     * reason at all: the same instance with its **own** team is allowed.
     */
    $b = $this->teams['b']['team'];

    $allowed = app(TeamContext::class)->runFor(
        $b,
        fn () => app(SendRails::class)->decide($theirs, $b),
    );

    expect($allowed->allowed)->toBeTrue();
});
