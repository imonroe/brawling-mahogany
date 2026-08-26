<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\TeamMembership;
use App\Support\Automation\ExecuteAction;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Mail;

/**
 * F5.9's rails, from the screen a person actually uses (PRD §4.5 · #96).
 *
 * `SendingAutomationsTest` proves the rails hold in the worker. This proves
 * somebody can **reach** them, which is a separate question and the one
 * `CLAUDE.md` records as the S17 finding: *a row nothing can reach is a rule
 * nobody is following.* A kill switch with no hand on it is a column.
 */
beforeEach(function (): void {
    Mail::fake();

    [$this->team, $this->colleague] = $this->teamWithMember();

    $this->owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole())->person;

    $this->actingAsPerson($this->enrollTwoFactor($this->owner), $this->team);

    $this->team->forceFill([
        'sandbox_mode' => false,
        'approval_required_until' => now()->subDay(),
    ])->save();

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('says how many messages the switch would hold', function (): void {
    /*
     * F5.9 promises the switch *"must catch everything already queued"*, and a
     * number is the difference between somebody believing that and hoping so.
     */
    ActionInstance::factory()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    ActionInstance::factory()->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->get('/settings/sending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/SendSafety')
            // Three queued; the sent one is not something a switch can hold.
            ->where('queued', 3));
});

it('stops everything already queued', function (): void {
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->patch('/settings/sending', [
        'sends_disabled' => true,
        'sends_disabled_reason' => 'The Vanterpool template is sending to the wrong person.',
        'sandbox_mode' => false,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->assertRedirect();

    // The rail is asked in the worker rather than at dispatch, so the proof is
    // that a message already on its way stops.
    app(ExecuteAction::class)->handle($instance, $this->team->fresh());

    Mail::assertNothingSent();

    expect($instance->fresh()->state)->toBe(AutomationState::Pending)
        ->and($instance->fresh()->error)->toBe('The Vanterpool template is sending to the wrong person.');
});

it('releases what it was holding when the switch goes back off', function (): void {
    $this->team->forceFill(['sends_disabled_at' => now(), 'sends_disabled_reason' => 'Stopped.'])->save();

    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->patch('/settings/sending', [
        'sends_disabled' => false,
        'sandbox_mode' => false,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ]);

    app(ExecuteAction::class)->handle($instance, $this->team->fresh());

    expect($instance->fresh()->state)->toBe(AutomationState::Sent);
});

it('does not reset how long sending has been off when another field is saved', function (): void {
    /*
     * *"Sending has been off since Tuesday"* is what somebody needs to read on
     * this screen, and saving the form to change an hourly limit must not
     * quietly make it today.
     */
    $stoppedAt = now()->subDays(3);

    $this->team->forceFill(['sends_disabled_at' => $stoppedAt])->save();

    $this->patch('/settings/sending', [
        'sends_disabled' => true,
        'sandbox_mode' => false,
        'hourly_send_limit' => 30,
        'daily_send_limit' => 200,
    ]);

    expect($this->team->fresh()->sends_disabled_at->toDateTimeString())
        ->toBe($stoppedAt->toDateTimeString());
});

it('refuses a limit of zero', function (): void {
    // Zero is `sends_disabled` said a second way, and two spellings of one
    // state is how a team ends up with sending off and the switch reading on.
    $this->patch('/settings/sending', [
        'sends_disabled' => false,
        'sandbox_mode' => false,
        'hourly_send_limit' => 0,
        'daily_send_limit' => 200,
    ])->assertSessionHasErrors('hourly_send_limit');
});

it('clears the reason when sending is turned back on', function (): void {
    // A reason left behind is a screen saying why sending is off, on a team
    // whose sending is not.
    $this->team->forceFill([
        'sends_disabled_at' => now(),
        'sends_disabled_reason' => 'Stopped while we fix a template.',
    ])->save();

    $this->patch('/settings/sending', [
        'sends_disabled' => false,
        'sandbox_mode' => false,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ]);

    expect($this->team->fresh()->sends_disabled_reason)->toBeNull();
});

it('audits who stopped it', function (): void {
    // PRD §9: turning sending off stops every client communication the team
    // has automated. It belongs in the record that outlives the activity feed.
    $this->patch('/settings/sending', [
        'sends_disabled' => true,
        'sends_disabled_reason' => 'A client called.',
        'sandbox_mode' => false,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ]);

    $entry = AuditEntry::query()->where('action', 'team.send_safety_updated')->sole();

    expect($entry->actor_person_id)->toBe($this->owner->getKey())
        ->and($entry->reason)->toBe('A client called.');
});

it('turns sandbox mode on from the screen', function (): void {
    $this->patch('/settings/sending', [
        'sends_disabled' => false,
        'sandbox_mode' => true,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ]);

    expect($this->team->fresh()->sandbox_mode)->toBeTrue();
});

it('does not let an ordinary member reach the switch', function (): void {
    /*
     * `settings.manage`, the same permission the rest of team settings takes.
     * Stopping every outbound message a team sends is not something an
     * ordinary member should be able to do quietly — and neither is starting
     * it again.
     */
    $this->actingAsPerson($this->colleague, $this->team);

    $this->get('/settings/sending')->assertForbidden();

    $this->patch('/settings/sending', [
        'sends_disabled' => true,
        'sandbox_mode' => false,
        'hourly_send_limit' => 60,
        'daily_send_limit' => 200,
    ])->assertForbidden();

    expect($this->team->fresh()->sendsAreDisabled())->toBeFalse();
});
