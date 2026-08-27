<?php

declare(strict_types=1);

use App\Enums\EventType;
use App\Models\Deal;
use App\Models\Event;
use App\Models\KeyDate;
use App\Support\Calendar\Recurrence;
use Inertia\Testing\AssertableInertia;

/**
 * S57 and S58 (PRD §4.8 F8.1 · issue #105).
 *
 * The definition of done, as assertions: the three views render, deadlines and
 * events are distinguishable, and times are the team's.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('renders the month grid over the weeks it actually draws', function (): void {
    /*
     * The window is the grid's, not the calendar month's: a month view starts
     * on the Sunday before the first and ends on the Saturday after the last,
     * and a query that fetched only the month would leave the leading and
     * trailing cells silently empty.
     */
    $this->get('/calendar?view=month&date=2026-09-15')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Index')
            ->where('view', 'month')
            ->where('range.from', '2026-08-30')
            ->where('range.to', '2026-10-03'));
});

it('renders the week and agenda windows', function (): void {
    $this->get('/calendar?view=week&date=2026-09-15')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range.from', '2026-09-13')
            ->where('range.to', '2026-09-19'));

    $this->get('/calendar?view=agenda&date=2026-09-15')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range.from', '2026-09-15')
            ->where('range.to', '2026-09-29'));
});

it('falls back to this month rather than failing on a nonsense date', function (): void {
    // A `GET` somebody arrives at from a bookmark with a typo in it. A 422 on
    // a calendar URL is worse than showing them this month.
    $this->get('/calendar?view=month&date=2026-02-31')->assertOk();
    $this->get('/calendar?date=not-a-date')->assertOk();
});

it('puts events and deadlines on one grid, and keeps them apart', function (): void {
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Inspection',
        'type' => EventType::Inspection,
        'starts_at' => '2026-09-15 16:00:00',
        'ends_at' => '2026-09-15 17:00:00',
    ]);

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $this->get('/calendar?view=agenda&date=2026-09-15')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $items = $page->toArray()['props']['items'];

            expect($items)->toHaveCount(2)
                /*
                 * The deadline sorts first. Not a layout preference: a
                 * deadline is the thing on that square with legal
                 * consequences, and sorting a timeless row to the end would
                 * put *"inspection objection expires today"* under a 4pm
                 * showing.
                 */
                ->and($items[0]['kind'])->toBe('deadline')
                ->and($items[1]['kind'])->toBe('event');
        });
});

it('leaves an unconfirmed extracted date off the calendar', function (): void {
    /*
     * #107: *"it must not be counted as a deadline until confirmed."* The
     * calendar is where counting it would do the most damage — a machine's
     * reading of a contract, drawn beside real deadlines, is a date somebody
     * plans around.
     */
    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'date' => '2026-09-15',
    ]);

    $this->get('/calendar?view=agenda&date=2026-09-15')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items', []));
});

it('expands a repeat inside the window without storing a row per occurrence', function (): void {
    Event::factory()->weekly('2026-10-31')->create([
        'team_id' => $this->team->getKey(),
        'title' => 'Open house',
        'starts_at' => '2026-09-05 11:00:00',
        'ends_at' => '2026-09-05 13:00:00',
    ]);

    $this->get('/calendar?view=week&date=2026-09-15')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $items = $page->toArray()['props']['items'];

            expect($items)->toHaveCount(1)
                ->and($items[0]['day'])->toBe('2026-09-19')
                ->and($items[0]['isRepeat'])->toBeTrue();
        });

    expect(Event::query()->count())->toBe(1);
});

it('keeps a repeat visible long after the series began', function (): void {
    /*
     * The defect a naive expansion has: stepping one interval at a time from
     * the first occurrence needs 1,095 steps to reach today for a daily series
     * three years old — past the loop's own guard — so the guard meant to stop
     * an infinite loop would instead return **nothing**, silently, for the
     * window somebody is looking at.
     */
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'title' => 'Daily standup',
        'starts_at' => '2023-01-02 09:00:00',
        'ends_at' => '2023-01-02 09:15:00',
        'recurrence' => ['frequency' => Recurrence::DAILY, 'interval' => 1, 'until' => null],
    ]);

    $this->get('/calendar?view=week&date=2026-09-15')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect($page->toArray()['props']['items'])->toHaveCount(7);
        });
});

it('reads a picked time as the team’s wall clock and stores UTC', function (): void {
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->withTeam($this->team->refresh());

    $this->post('/calendar/events', [
        'type' => EventType::Closing->value,
        'title' => 'Closing appointment',
        'startsAt' => '2026-09-15T09:00',
        'endsAt' => '2026-09-15T10:00',
        'returnView' => 'month',
        'returnDate' => '2026-09-15',
    ])->assertRedirect();

    $event = Event::query()->sole();

    // 9am in Denver is 15:00 UTC in September. A colleague reading this from
    // another zone must see the closing at the hour the closing is.
    expect($event->starts_at->toDateTimeString())->toBe('2026-09-15 15:00:00')
        ->and($event->startsIn('America/Denver')->format('H:i'))->toBe('09:00');
});

it('normalises an all-day event to the team’s midnight', function (): void {
    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->withTeam($this->team->refresh());

    $this->post('/calendar/events', [
        'type' => EventType::OpenHouse->value,
        'title' => 'Open house',
        'startsAt' => '2026-09-19T14:30',
        'isAllDay' => true,
        'returnView' => 'month',
        'returnDate' => '2026-09-19',
    ])->assertRedirect();

    $event = Event::query()->sole();

    /*
     * A stored 14:30 under `is_all_day` would surface the moment somebody
     * sorted by `starts_at` or read the row without the flag.
     */
    expect($event->is_all_day)->toBeTrue()
        ->and($event->startsIn('America/Denver')->format('H:i'))->toBe('00:00')
        ->and($event->ends_at)->toBeNull();
});

it('refuses an event that ends before it starts', function (): void {
    $this->post('/calendar/events', [
        'type' => EventType::Showing->value,
        'title' => 'Backwards',
        'startsAt' => '2026-09-15T14:00',
        'endsAt' => '2026-09-15T13:00',
    ])->assertSessionHasErrors('endsAt');
});

it('writes a timeline entry only for an event that belongs to a deal', function (): void {
    $this->post('/calendar/events', [
        'type' => EventType::Inspection->value,
        'title' => 'Inspection',
        'startsAt' => '2026-09-15T09:00',
        'dealId' => $this->deal->getKey(),
    ])->assertRedirect();

    $this->post('/calendar/events', [
        'type' => EventType::OpenHouse->value,
        'title' => 'Open house',
        'startsAt' => '2026-09-19T11:00',
    ])->assertRedirect();

    /*
     * `activity_events.deal_id` is *where a team looks for it*, and an open
     * house with no deal has nowhere to be looked for. Recording it against
     * nothing to look thorough would be an entry nobody can reach.
     */
    expect(App\Models\ActivityEvent::query()->where('event_type', 'event.added')->count())->toBe(1);
});

it('tells a moved event apart from an edited one', function (): void {
    $event = Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Inspection',
        'starts_at' => '2026-09-15 16:00:00',
    ]);

    $this->patch("/calendar/events/{$event->getKey()}", [
        'type' => EventType::Inspection->value,
        'title' => 'Inspection',
        'startsAt' => '2026-09-17T16:00',
        'dealId' => $this->deal->getKey(),
    ])->assertRedirect();

    expect(App\Models\ActivityEvent::query()->where('event_type', 'event.moved')->exists())->toBeTrue();

    $this->patch("/calendar/events/{$event->getKey()}", [
        'type' => EventType::Inspection->value,
        'title' => 'Inspection with the new firm',
        'startsAt' => '2026-09-17T16:00',
        'dealId' => $this->deal->getKey(),
    ])->assertRedirect();

    expect(App\Models\ActivityEvent::query()->where('event_type', 'event.edited')->exists())->toBeTrue();
});

it('says on the old deal that an event has left it', function (): void {
    /*
     * The half an edit that only ever *gains* entries would lose: an
     * inspection taken off a deal disappears from that deal's calendar, and
     * somebody looking for an appointment that is no longer there needs
     * something to read.
     */
    $event = Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Inspection',
        'starts_at' => '2026-09-15 16:00:00',
    ]);

    $this->patch("/calendar/events/{$event->getKey()}", [
        'type' => EventType::Inspection->value,
        'title' => 'Inspection',
        'startsAt' => '2026-09-15T16:00',
    ])->assertRedirect();

    expect($event->refresh()->deal_id)->toBeNull()
        ->and(App\Models\ActivityEvent::query()
            ->where('event_type', 'event.removed')
            ->where('deal_id', $this->deal->getKey())
            ->exists())->toBeTrue();
});
