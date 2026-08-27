<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Models\TeamMembership;

/**
 * S08's panel and S78's preferences (F12.4 · issue #101).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('folds a burst into one line', function (): void {
    /*
     * #101: *"twelve 'task assigned' notifications from one workflow
     * instantiation should read as one line, not twelve."* That burst is
     * exactly what attaching a workflow produces, and a panel that draws
     * twelve lines for it is a panel whose badge means "a workflow started".
     */
    Notification::factory()->count(12)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $this->deal->getKey(),
        'type' => NotificationType::TaskAssigned,
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->has('groups', 1)
            ->where('groups.0.count', 12)
            ->where('groups.0.summary', '12 tasks were assigned to you'));
});

it('never folds an override, because two of them are two facts', function (): void {
    /*
     * IA §7 makes an override legally distinct and `AdvanceWorkflow` writes
     * four artefacts for one. *"2 requirements were overridden"* is the
     * summary that stops somebody reading either.
     */
    Notification::factory()->count(2)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $this->deal->getKey(),
        'type' => NotificationType::GateOverridden,
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('shows a person their notifications from every team they are in', function (): void {
    /*
     * #101: *"a person in two teams needs to know which one a notification
     * came from, and switching teams should not hide it."* A stager working
     * two agencies who is told at nine that a task is theirs must not lose it
     * by switching at ten.
     */
    [$other] = $this->teamWithMember($this->member);

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'summary' => 'From the first team',
    ]);

    /*
     * Written inside the other team's context — `BelongsToTeam`'s cross-tenant
     * guard is doing its job, and in production this row is written by a
     * worker running under `RunsForTeam` for that team.
     */
    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        Notification::factory()->create([
            'team_id' => $other->getKey(),
            'person_id' => $this->member->getKey(),
            'summary' => 'From the second team',
        ]);
    });

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('shows nobody else’s notifications', function (): void {
    $stranger = Person::factory()->create();

    TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'first_name' => 'Sam',
        'last_name' => 'Reilly',
        'email' => 'sam@example.test',
    ]);

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'summary' => 'Not for you',
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 0));
});

it('marks one read without touching the rest', function (): void {
    $mine = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $other = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->post('/notifications/read', ['notification' => $mine->getKey()])
        ->assertRedirect();

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();
});

it('will not let somebody mark another person’s notification read', function (): void {
    $stranger = Person::factory()->create();

    $theirs = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
    ]);

    $this->post('/notifications/read', ['notification' => $theirs->getKey()])
        ->assertRedirect();

    // The predicate *is* the authorization: the update is scoped to the person
    // asking, so a stranger's id simply matches nothing.
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all read', function (): void {
    Notification::factory()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->post('/notifications/read')->assertRedirect();

    expect(Notification::query()->forPerson($this->member)->unread()->count())->toBe(0);
});

it('offers S78 the channels somebody may actually choose', function (): void {
    /*
     * `in_app` is not offered — the panel is the record and cannot be switched
     * off — and `push` is not offered until #103 exists, because a switch that
     * does nothing is worse than an absent one.
     */
    $this->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Notifications')
            ->has('types', count(NotificationType::cases()))
            ->has('channels', 1)
            ->where('channels.0.value', 'email')
            ->has('comingSoon', 1));
});

it('saves a preference and a quiet-hours window', function (): void {
    $this->patch('/settings/notifications', [
        'channels' => [NotificationType::GateCleared->value => ['email']],
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '07:00',
    ])->assertRedirect();

    $preference = NotificationPreference::query()->sole();

    expect($preference->channels[NotificationType::GateCleared->value])->toBe(['email'])
        ->and($preference->hasQuietHours())->toBeTrue();
});

it('refuses half a quiet-hours window', function (): void {
    /*
     * The failure a half-set window produces is the worst kind: a person
     * believes they have set quiet hours and the sends go out anyway.
     */
    $this->patch('/settings/notifications', ['quiet_hours_start' => '21:00'])
        ->assertSessionHasErrors('quiet_hours_end');

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('refuses a channel nothing can deliver on', function (): void {
    $this->patch('/settings/notifications', [
        'channels' => [NotificationType::GateCleared->value => ['push']],
    ])->assertSessionHasErrors('channels.'.NotificationType::GateCleared->value.'.0');
});
