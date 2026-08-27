<?php

declare(strict_types=1);

use App\Console\Commands\NotifyAboutKeyDates;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Carbon;

/**
 * S88 — deadline reminders ahead of a key date (PRD §4.8 F8.4 · issue #109).
 *
 * The definition of done: reminders fire ahead of dates, aggregated per day,
 * in the team timezone; moving a date reschedules them; closing a deal cancels
 * them.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->withTeam($this->team->refresh());

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    /*
     * A key date has no assignee — it is a fact about the deal, not work owed
     * — so the audience is everybody in the team who could act on it, which is
     * the owner as well as the member signed in here. Every assertion below is
     * therefore about **this person's** rows: a bare count would be measuring
     * how many colleagues the fixture happens to build.
     */
    $this->mine = fn (NotificationType $type): Notification => Notification::query()
        ->where('person_id', $this->member->getKey())
        ->where('type', $type->value)
        ->sole();

    $this->minesCount = fn (): int => Notification::query()
        ->where('person_id', $this->member->getKey())
        ->count();
});

/** Freeze the clock at the team's 8am on a given day. */
function atTeamMorning(string $day): void
{
    Carbon::setTestNow(
        Carbon::parse($day.' '.NotifyAboutKeyDates::HOUR.':05:00', 'America/Denver')->utc(),
    );
}

afterEach(function (): void {
    Carbon::setTestNow();
});

function keyDate(array $attributes = []): KeyDate
{
    return KeyDate::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);
}

it('reminds a week out and the day before, and not in between', function (): void {
    /*
     * The test was named for both halves and asserted only the first, and the
     * second did not work: the once-only guard was keyed on *"this date, for
     * this day"*, which is constant across the whole schedule — so the seven-
     * day notice recorded the key and the day-before read as already sent.
     *
     * A schedule with more than one offset needs the **offset** in the key. A
     * test that stops at the first one cannot see that, which is why this one
     * runs the whole schedule and the days in between it.
     */
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-15']);

    // Nine days out: not one of the default offsets.
    atTeamMorning('2026-09-06');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(0);

    // Seven days out.
    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(1);

    // Six, five, four, three, two: none of them an offset, and none of them a
    // repeat of the one already sent.
    foreach (['09-09', '09-10', '09-11', '09-12', '09-13'] as $quiet) {
        atTeamMorning('2026-'.$quiet);
        $this->artisan('notifications:key-dates')->assertExitCode(0);
    }

    expect(($this->minesCount)())->toBe(1);

    // The day before, which is the half the name promised.
    atTeamMorning('2026-09-14');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(2);

    // And twice on the same morning is still once.
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(2);
});

it('runs a critical date’s whole schedule, not just its first step', function (): void {
    /*
     * Five offsets, and the same guard: fourteen, seven, three, one, and the
     * morning it falls. The day-of survived the defect only because it is its
     * own `NotificationType` — it routed around the guard rather than passing
     * it — so a test that checked the ends would have seen two of five and
     * called it working.
     */
    keyDate(['name' => 'Financing contingency', 'date' => '2026-09-20', 'is_critical' => true]);

    foreach (['09-06', '09-13', '09-17', '09-19'] as $index => $morning) {
        atTeamMorning('2026-'.$morning);
        $this->artisan('notifications:key-dates')->assertExitCode(0);

        expect(($this->minesCount)())->toBe($index + 1);
    }

    // And the morning itself, which is its own type and bypasses quiet hours.
    atTeamMorning('2026-09-20');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(5)
        ->and(Notification::query()
            ->where('person_id', $this->member->getKey())
            ->where('type', NotificationType::CriticalDateToday->value)
            ->count())->toBe(1);
});

it('only runs in the hour that is 8am where the team is', function (): void {
    keyDate(['date' => '2026-09-15']);

    // Eight in the morning in UTC is 2am in Denver.
    Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00', 'UTC'));

    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(0);
});

it('sends one notification covering several dates, not one each', function (): void {
    /*
     * #109: *"several deadlines on one day should produce **one** email, not
     * four. That is the difference between a useful reminder and one that gets
     * filtered."*
     */
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-15']);
    keyDate(['name' => 'Appraisal', 'date' => '2026-09-15']);
    keyDate(['name' => 'Loan commitment', 'date' => '2026-09-15']);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    $notification = ($this->mine)(NotificationType::DeadlineApproaching);

    expect($notification->summary)->toBe('3 deadlines coming up')
        ->and($notification->data['lines'])->toHaveCount(3)
        // The digest spans deals, so it belongs to none of them: the panel line
        // would otherwise link somewhere arbitrary.
        ->and($notification->deal_id)->toBeNull();
});

it('names the one date when there is only one', function (): void {
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-09']);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    $notification = ($this->mine)(NotificationType::DeadlineApproaching);

    expect($notification->summary)->toBe('Inspection objection is tomorrow')
        ->and($notification->deal_id)->toBe($this->deal->getKey());
});

it('does not repeat a reminder it has already sent', function (): void {
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-09']);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(1);
});

it('reminds again when the date moves, with nothing to reschedule', function (): void {
    /*
     * There is no reminder table and no delayed job: the sweep asks every
     * morning what is owed. The once-only guard is keyed on the date being
     * reminded *about*, so a deadline moved to a new day has never been
     * reminded about for that day.
     */
    $date = keyDate(['name' => 'Inspection objection', 'date' => '2026-09-09']);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    app(App\Support\Dates\SaveKeyDate::class)->edit($date, ['date' => '2026-09-16']);

    // The old day is remembered, so the same morning says nothing more.
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(1);

    // A week out from the new day, it is owed again.
    atTeamMorning('2026-09-09');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(2);
});

it('still reminds when a moved date lands on a day another date in the same digest already claimed', function (): void {
    /*
     * The once-only guard is per person, per date, per **day**, and a digest
     * carries several dates at once. Storing their ids and their days as two
     * parallel arrays made the guard match *"this id is somewhere in the row"*
     * AND *"this day is somewhere in the row"* independently — true of a row
     * where the id and the day belong to different dates.
     *
     * Which is exactly the case this feature exists for. One digest names both
     * dates below; *Appraisal* then slips onto the day *Inspection* already
     * claimed, and the sweep decided it had already said so. The moved
     * deadline — the one thing a reminder sweep is for — went out to nobody.
     */
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-08']);
    $appraisal = keyDate(['name' => 'Appraisal', 'date' => '2026-09-02']);

    // One digest: Inspection at seven days out, Appraisal at one.
    atTeamMorning('2026-09-01');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(1);

    app(App\Support\Dates\SaveKeyDate::class)->edit($appraisal, ['date' => '2026-09-08']);

    // The morning before the new day. Appraisal has never been announced for
    // the 8th; Inspection has.
    atTeamMorning('2026-09-07');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(2);
});

it('lets a date turn its own reminders off, but not a critical date’s day-of notice', function (): void {
    /*
     * `reminder_offsets` answers *"how much warning do I want"*, and an empty
     * list is a real answer — a routine date somebody would rather not hear
     * about in advance.
     *
     * It silenced the **critical** day-of notice too, which is the one PRD
     * §12.3 exists for: *"a missed inspection deadline is a legal problem."*
     * Marking a date critical and clearing its reminders is a contradiction,
     * and of the two the flag is what somebody set deliberately about
     * consequences. So the schedule governs the warnings and the alarm is not
     * on it.
     */
    $ordinary = keyDate(['name' => 'Walkthrough', 'date' => '2026-09-09', 'reminder_offsets' => []]);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(0);

    // The control: the same date on the default schedule is announced.
    $ordinary->forceFill(['reminder_offsets' => null])->save();

    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(1);

    $critical = keyDate([
        'name' => 'Financing contingency',
        'date' => '2026-09-10',
        'is_critical' => true,
        'reminder_offsets' => [],
    ]);

    // Nothing in advance, which is what turning them off asked for…
    atTeamMorning('2026-09-09');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(Notification::query()
        ->where('person_id', $this->member->getKey())
        ->where('type', NotificationType::CriticalDateToday->value)
        ->count())->toBe(0);

    // …and the notice on the morning it falls, which it did not.
    atTeamMorning('2026-09-10');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(Notification::query()
        ->where('person_id', $this->member->getKey())
        ->where('type', NotificationType::CriticalDateToday->value)
        ->count())->toBe(1)
        ->and($critical->fresh()->reminder_offsets)->toBe([]);
});

it('says nothing about a deal that has closed', function (): void {
    keyDate(['name' => 'Closing', 'date' => '2026-09-09']);

    $this->deal->forceFill(['state' => App\Enums\DealState::Closed, 'closed_at' => now()])->save();

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(0);
});

it('says nothing about a date nobody has confirmed', function (): void {
    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Suggested closing',
        'date' => '2026-09-09',
    ]);

    atTeamMorning('2026-09-08');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    expect(($this->minesCount)())->toBe(0);
});

it('is louder about a critical date, and tells somebody on the day', function (): void {
    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-15', 'is_critical' => true]);

    // Fourteen days out is a critical-only offset.
    atTeamMorning('2026-09-01');
    $this->artisan('notifications:key-dates')->assertExitCode(0);
    expect(($this->minesCount)())->toBe(1);

    // And the day itself, which an ordinary date never gets.
    atTeamMorning('2026-09-15');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    $today = ($this->mine)(NotificationType::CriticalDateToday);

    expect($today->summary)->toBe('Inspection objection is today');
});

it('wakes somebody for a critical deadline today, and not for one next week', function (): void {
    /*
     * #101 left `bypassesQuietHours()` false for everything and named this as
     * the case where a `true` would belong. PRD §12.3: *"a missed inspection
     * deadline is a legal problem"* — and there is nothing to be done about
     * today's tomorrow morning.
     */
    (new NotificationPreference)->forceFill([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'channels' => [],
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '09:00',
    ])->save();

    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-15', 'is_critical' => true]);

    // And an ordinary one a week out, so both kinds are raised in the same
    // sweep — the comparison is the point, and a test that only produced the
    // urgent one could not tell a bypass from a missing quiet-hours rule.
    keyDate(['name' => 'Loan commitment', 'date' => '2026-09-22']);

    atTeamMorning('2026-09-15');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    $today = ($this->mine)(NotificationType::CriticalDateToday);
    $ahead = ($this->mine)(NotificationType::DeadlineApproaching);

    // 8am is inside a 21:00–09:00 window, so the ordinary digest waits.
    expect($today->deliver_after)->toBeNull()
        ->and($ahead->deliver_after)->not->toBeNull();
});

it('leaves the panel row whatever somebody has switched off', function (): void {
    /*
     * #109 asks that a critical date be *"impossible to accidentally
     * disable"*. `in_app` is added back whatever is stored (ADR 0003's second
     * door), so the record always exists — which is what makes that true
     * without taking a preference away from anybody.
     */
    (new NotificationPreference)->forceFill([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'channels' => [NotificationType::CriticalDateToday->value => []],
    ])->save();

    keyDate(['name' => 'Inspection objection', 'date' => '2026-09-15', 'is_critical' => true]);

    atTeamMorning('2026-09-15');
    $this->artisan('notifications:key-dates')->assertExitCode(0);

    $notification = ($this->mine)(NotificationType::CriticalDateToday);

    expect($notification->channels)->toBe([NotificationChannel::InApp->value]);
});
