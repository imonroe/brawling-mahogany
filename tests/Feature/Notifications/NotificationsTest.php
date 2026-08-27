<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\InternalAlertMail;
use App\Models\Deal;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Support\Notifications\Notify;
use Illuminate\Support\Facades\Mail;

/**
 * Notifications, the panel and quiet hours (PRD §4.12 F12.4 · issue #101).
 */
/**
 * A second person in **this** team.
 *
 * `teamWithMember()` makes a team as well, which is the wrong shape here: a
 * notification about a task is scoped to the team the task is in, and a
 * colleague in a different team would make every assertion below pass for the
 * wrong reason.
 */
function notifiableColleague(): App\Models\Person
{
    $person = App\Models\Person::factory()->create();

    App\Models\TeamMembership::query()->create([
        'team_id' => test()->team->getKey(),
        'person_id' => $person->getKey(),
        'first_name' => 'Sam',
        'last_name' => 'Reilly',
        'email' => 'sam+'.$person->getKey().'@example.test',
    ]);

    return $person;
}

beforeEach(function (): void {
    Mail::fake();

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('does not send a 6am push to somebody with quiet hours set', function (): void {
    /*
     * Issue #101's definition of done, in as many words: *"a test proves a 6am
     * push does not go out to a user with quiet hours set."* Push itself is
     * #103, so what this holds is the rule the push will inherit — the
     * outbound channels are **held**, and the row is written anyway.
     *
     * Evaluated in the **team's** timezone (PRD §9). The team is in Denver and
     * the clock is set to 12:00 UTC, which is 06:00 there: a comparison made
     * in UTC, or in another team's zone, passes this fixture and fails the
     * person it is about.
     *
     * The date is deliberately **after** the March DST change (the second
     * Sunday, 2026-03-08), so Denver is UTC−6 rather than UTC−7. An earlier
     * draft of this test used 13:00Z and a date that straddled it, which lands
     * exactly on 07:00 — the edge of the window — and reported a bug that was
     * not there. Twice a year is when a quiet-hours rule is actually judged.
     */
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();

    NotificationPreference::factory()->quiet('21:00', '07:00')->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-10T12:00:00Z'));

    $written = app(Notify::class)->send(
        type: NotificationType::TaskAssigned,
        people: [$this->member],
        team: $this->team,
        summary: 'Order the survey',
        deal: $this->deal,
    );

    $notification = $written[0];

    expect($notification->deliver_after)->not->toBeNull()
        // Held until the window ends — 07:00 in Denver, which is 13:00 UTC.
        ->and($notification->deliver_after->utc()->format('H:i'))->toBe('13:00')
        ->and($notification->delivered_at)->toBeNull();

    // Nothing has gone out...
    Mail::assertNothingSent();

    // ...and the record exists regardless, which is what "delayed, not
    // dropped" means: somebody opening the app at 06:30 has already been told.
    expect(Notification::query()->forPerson($this->member)->unread()->count())->toBe(1);
});

it('sends immediately outside the window', function (): void {
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();

    NotificationPreference::factory()->quiet('21:00', '07:00')->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    // 16:00 UTC is 10:00 in Denver — a working morning.
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-10T16:00:00Z'));

    $written = app(Notify::class)->send(
        type: NotificationType::TaskAssigned,
        people: [$this->member],
        team: $this->team,
        summary: 'Order the survey',
        deal: $this->deal,
    );

    expect($written[0]->deliver_after)->toBeNull();
});

it('holds an evening notification until the next morning, not that morning', function (): void {
    /*
     * The wrapping window, and the half of it that is easy to get wrong. A
     * notification at 22:00 held until "07:00" resolves to an instant fifteen
     * hours in the **past** unless the day is advanced — so the sweep releases
     * it immediately and the quiet-hours setting does nothing while appearing
     * to work.
     */
    $this->team->forceFill(['timezone' => 'UTC'])->save();

    NotificationPreference::factory()->quiet('21:00', '07:00')->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-10T22:00:00Z'));

    $written = app(Notify::class)->send(
        type: NotificationType::TaskAssigned,
        people: [$this->member],
        team: $this->team,
        summary: 'Order the survey',
        deal: $this->deal,
    );

    expect($written[0]->deliver_after->toIso8601String())
        ->toBe('2026-03-11T07:00:00+00:00');
});

it('releases what quiet hours held, once the window closes', function (): void {
    $notification = Notification::factory()->held()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->travelTo(now()->addHours(9));

    $this->artisan('notifications:release-held')->assertSuccessful();

    app(App\Support\Notifications\SendNotification::class)
        ->deliver((string) $notification->getKey());

    expect($notification->fresh()->delivered_at)->not->toBeNull();

    Mail::assertSent(InternalAlertMail::class);
});

it('writes the row but owes nothing when a person wants only the panel', function (): void {
    NotificationPreference::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'channels' => [NotificationType::TaskAssigned->value => []],
    ]);

    $written = app(Notify::class)->send(
        type: NotificationType::TaskAssigned,
        people: [$this->member],
        team: $this->team,
        summary: 'Order the survey',
        deal: $this->deal,
    );

    expect($written[0]->channels)->toBe([NotificationChannel::InApp->value])
        // Nothing owed, so nothing pending: a row that is only ever a row has
        // no outbound state to be in.
        ->and($written[0]->delivered_at)->not->toBeNull();

    Mail::assertNothingSent();
});

it('cannot be told to stop keeping the record', function (): void {
    /*
     * `in_app` is not a preference. The panel is the record and ADR 0003's
     * second door for the other two channels, so a stored list without it —
     * written by hand, or by a later migration — still gets it back.
     */
    $preference = NotificationPreference::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'channels' => [NotificationType::GateCleared->value => ['email']],
    ]);

    expect($preference->channelsFor(NotificationType::GateCleared))
        ->toBe([NotificationChannel::InApp, NotificationChannel::Email]);
});

it('never tells somebody what they just did themselves', function (): void {
    $written = app(Notify::class)->send(
        type: NotificationType::GateCleared,
        people: [$this->member],
        team: $this->team,
        summary: 'A requirement cleared',
        deal: $this->deal,
        actor: $this->member,
    );

    expect($written)->toBe([])
        ->and(Notification::query()->count())->toBe(0);
});

it('tells somebody a task is theirs, however it was assigned', function (): void {
    /*
     * `assignee_id` is written from `DealTasks::add()`, `::edit()`,
     * `InstantiateWorkflow` and `AdvanceWorkflow::override()`'s follow-up. A
     * hook on one of them is a hook on none of them — `CLAUDE.md`'s
     * `gate_cleared` finding — so this writes the column directly, which is
     * what every one of those four does underneath.
     */
    $other = notifiableColleague();

    Task::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Order the survey',
        'assignee_id' => $other->getKey(),
    ]);

    $notification = Notification::query()->forPerson($other)->sole();

    expect($notification->type)->toBe(NotificationType::TaskAssigned)
        ->and($notification->summary)->toContain('Order the survey')
        ->and($notification->deal_id)->toBe($this->deal->getKey());
});

it('does not repeat itself when a task is saved again', function (): void {
    $other = notifiableColleague();

    $task = Task::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'assignee_id' => $other->getKey(),
    ]);

    $task->forceFill(['title' => 'Order the survey again'])->save();

    expect(Notification::query()->forPerson($other)->count())->toBe(1);
});
