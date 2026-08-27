<?php

declare(strict_types=1);

use App\Enums\EventType;
use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\Event;
use App\Models\KeyDate;
use App\Models\TeamMembership;
use App\Support\Calendar\ManageCalendarFeeds;
use App\Support\Tenancy\TeamContext;

/**
 * S60 — tokenised read-only iCal feeds (PRD §4.8 F8.3 · issue #108).
 *
 * The definition of done: a feed subscribes, revoking stops it immediately,
 * and cross-tenant access returns 404. Plus the rule #108 states about what a
 * feed may carry, which is the one a passing subscription would not catch.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->team->forceFill(['timezone' => 'America/Denver'])->save();
    $this->withTeam($this->team->refresh());

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $this->feeds = app(ManageCalendarFeeds::class);
});

/**
 * A live feed, and the plaintext token that opens it.
 *
 * `test()` rather than `$this`: a plain function in a Pest file has no bound
 * `$this`, and the closures above do — which is the whole of why this reads
 * differently from the `beforeEach` two lines up.
 */
function feedToken(?Deal $deal = null): string
{
    return app(ManageCalendarFeeds::class)->generate(
        test()->team,
        test()->member,
        $deal,
        $deal instanceof Deal ? 'That deal' : 'Everything',
    )->token;
}

it('serves a calendar a client can subscribe to', function (): void {
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Inspection',
        'type' => EventType::Inspection,
        'starts_at' => now()->addWeek()->setTime(16, 0),
        'ends_at' => now()->addWeek()->setTime(17, 0),
        'location' => '123 Main St',
    ]);

    $token = feedToken();

    auth()->logout();

    $response = $this->get("/calendar/feeds/{$token}.ics")->assertOk();

    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($body)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and($body)->toContain('END:VCALENDAR')
        ->and($body)->toContain('BEGIN:VEVENT')
        ->and($body)->toContain('SUMMARY:Inspection')
        ->and($body)->toContain('LOCATION:123 Main St')
        // RFC 5545 requires CRLF, and several clients enforce it.
        ->and($body)->toContain("\r\n");
});

it('draws a deadline as a labelled all-day event', function (): void {
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-09-15',
    ]);

    $this->travelTo('2026-09-10 12:00:00');

    $token = feedToken();

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    /*
     * #108: *"deadlines as all-day events, clearly labelled."* iCal has no
     * *deadline*, so without the prefix a legally significant date looks
     * exactly like a showing on somebody's phone.
     */
    expect($body)->toContain('SUMMARY:Deadline: Inspection objection')
        ->and($body)->toContain('DTSTART;VALUE=DATE:20260915')
        /*
         * The **day after**: RFC 5545's end is exclusive for a date value, and
         * getting it wrong draws a one-day deadline across two squares in
         * every calendar client at once.
         */
        ->and($body)->toContain('DTEND;VALUE=DATE:20260916')
        // Nobody attends a deadline, so it must not make somebody look busy.
        ->and($body)->toContain('TRANSP:TRANSPARENT');
});

it('carries no attendee, no description, and no address book', function (): void {
    $attendee = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'email' => 'dana@example.test',
        'phone' => '555-0100',
    ]);

    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Showing',
        'description' => 'Lockbox code 4412',
        'attendees' => [$attendee->person_id],
        'starts_at' => now()->addDays(2)->setTime(10, 0),
    ]);

    $token = feedToken();

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    /*
     * #108: *"serving no PII beyond what the calendar needs — a client's phone
     * number does not belong in an event description that syncs to a
     * third-party calendar."* This document lands in Google's copy of somebody's
     * calendar and stays there.
     */
    expect($body)->toContain('SUMMARY:Showing')
        ->and($body)->not->toContain('ATTENDEE')
        ->and($body)->not->toContain('dana@example.test')
        ->and($body)->not->toContain('555-0100')
        ->and($body)->not->toContain('Dana Okafor')
        // Free text somebody typed, which is never safe by construction.
        ->and($body)->not->toContain('Lockbox code 4412')
        ->and($body)->not->toContain('DESCRIPTION');
});

it('escapes a value that would otherwise end the property early', function (): void {
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        // A title carrying every character RFC 5545 §3.3.11 reserves.
        'title' => "Open house; bring flyers, note\nBEGIN:VEVENT",
        'starts_at' => now()->addDay()->setTime(11, 0),
    ]);

    $token = feedToken();

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    /*
     * Content injection rather than a cosmetic problem: an unescaped newline
     * in a tenant's own title would put a second event into a subscriber's
     * calendar. One `VEVENT` went in, so one must come out.
     *
     * Counted as **lines**, not with `substr_count`. The escaped text still
     * contains the characters `BEGIN:VEVENT` — inside the SUMMARY value, where
     * they are inert — so a substring count says two and means nothing. What
     * decides whether a client sees one event or two is whether a *line*
     * begins with it, which is exactly what the escape prevents.
     *
     * Unfolded first, because a long summary is split across continuation
     * lines and the injected text could land at the start of one.
     */
    $unfolded = str_replace("\r\n ", '', $body);

    $starts = array_filter(
        explode("\r\n", $unfolded),
        static fn (string $line): bool => $line === 'BEGIN:VEVENT',
    );

    expect($starts)->toHaveCount(1)
        ->and($body)->toContain('\;')
        ->and($body)->toContain('\,')
        ->and($body)->toContain('\n');
});

it('folds a long line the way the standard requires', function (): void {
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'title' => str_repeat('A very long showing title ', 8),
        'starts_at' => now()->addDay()->setTime(11, 0),
    ]);

    $token = feedToken();

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    // RFC 5545 §3.1: no line over 75 octets. Some clients reject the file.
    foreach (explode("\r\n", trim($body)) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    // And a continuation begins with a space, or the fold is a truncation.
    expect($body)->toContain("\r\n ");
});

it('narrows a per-deal feed to that deal', function (): void {
    $otherDeal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'On the subject deal',
        'starts_at' => now()->addDay()->setTime(11, 0),
    ]);

    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $otherDeal->getKey(),
        'title' => 'On another deal',
        'starts_at' => now()->addDay()->setTime(14, 0),
    ]);

    $token = feedToken($this->deal);

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    expect($body)->toContain('On the subject deal')
        ->and($body)->not->toContain('On another deal');
});

it('stops the moment a feed is revoked', function (): void {
    $issued = $this->feeds->generate($this->team, $this->member, null, 'Everything');

    auth()->logout();

    $this->get("/calendar/feeds/{$issued->token}.ics")->assertOk();

    $this->feeds->revoke($issued->refresh());

    /*
     * A 404, never a 403. A calendar client is not a person and cannot read a
     * refusal — and the difference between *wrong* and *revoked* is exactly
     * what would confirm to an attacker that a token had once been real.
     */
    $this->get("/calendar/feeds/{$issued->token}.ics")->assertNotFound();
});

it('answers an unknown token exactly as it answers a revoked one', function (): void {
    auth()->logout();

    $this->get('/calendar/feeds/'.str_repeat('z', 43).'.ics')->assertNotFound();
});

it('replaces the feed it already had for the same subject', function (): void {
    $first = $this->feeds->generate($this->team, $this->member, null, 'Everything');
    $second = $this->feeds->generate($this->team, $this->member, null, 'Everything again');

    /*
     * A person who presses Generate twice means *"give me a URL"*, not *"give
     * me two"* — and two live feeds is two URLs to revoke and no way to tell
     * which is in which calendar.
     */
    expect($first->refresh()->isRevoked())->toBeTrue()
        ->and($second->isRevoked())->toBeFalse()
        ->and(CalendarFeed::query()->live()->count())->toBe(1)
        ->and($first->token)->not->toBe($second->token);
});

it('stores the token hashed, and encrypted rather than in the clear', function (): void {
    $feed = $this->feeds->generate($this->team, $this->member, null, 'Everything');

    $raw = Illuminate\Support\Facades\DB::table('calendar_feeds')
        ->where('id', $feed->getKey())
        ->first();

    /*
     * Hashed for the lookup, encrypted for S60's *copy URL* — the two answer
     * different questions and a feed needs both (see the migration). What is
     * never in the table is the plaintext.
     */
    expect($raw->token_hash)->toBe(hash('sha256', $feed->token))
        ->and($raw->token)->not->toBe($feed->token)
        ->and($feed->refresh()->token)->toBe($feed->token);
});

it('notes that a calendar read it, without an entry per fetch', function (): void {
    $issued = $this->feeds->generate($this->team, $this->member, null, 'Everything');

    auth()->logout();

    $this->get("/calendar/feeds/{$issued->token}.ics");
    $this->get("/calendar/feeds/{$issued->token}.ics");

    /*
     * A subscribed client polls every few hours forever, so a row per fetch
     * would be the noisiest table in the product. What is worth knowing is
     * whether it is still being read, and when last.
     */
    expect($issued->refresh()->fetch_count)->toBe(2)
        ->and($issued->last_fetched_at)->not->toBeNull();
});

it('reaches nothing belonging to another team', function (): void {
    [$otherTeam, $otherMember] = $this->teamWithMember();

    $theirs = app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam, $otherMember): string {
        $deal = Deal::factory()->create(['team_id' => $otherTeam->getKey()]);

        Event::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => $deal->getKey(),
            'title' => 'Their showing',
            'starts_at' => now()->addDay()->setTime(9, 0),
        ]);

        return app(ManageCalendarFeeds::class)
            ->generate($otherTeam, $otherMember, null, 'Theirs')
            ->token;
    });

    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'title' => 'Our showing',
        'starts_at' => now()->addDay()->setTime(10, 0),
    ]);

    auth()->logout();

    $body = $this->get("/calendar/feeds/{$theirs}.ics")->assertOk()->getContent();

    /*
     * The token establishes exactly one tenant, and the render runs inside
     * `runFor()` on it — so the board query is scoped exactly as any screen's
     * is. #42's requirement, on the one reader with no session.
     */
    expect($body)->toContain('Their showing')
        ->and($body)->not->toContain('Our showing');
});
