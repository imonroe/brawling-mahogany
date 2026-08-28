<?php

declare(strict_types=1);

use App\Actions\Teams\ProvisionTeam;
use App\Enums\DealSide;
use App\Enums\EventType;
use App\Enums\ParticipantRole;
use App\Enums\SystemRole;
use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Event;
use App\Models\KeyDate;
use App\Models\Permission;
use App\Models\Property;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Calendar\ManageCalendarFeeds;
use App\Support\Deals\DealRoster;
use App\Support\Deals\NameDeal;
use App\Support\Permissions;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

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

it('hands the newly generated feed to the screen that highlights it', function (): void {
    /*
     * `back()->with()` puts it in the flash bag, and nothing shares that bag as
     * an Inertia prop — so S60's *"which one did I just make"* highlight read a
     * prop nothing supplied and never drew. The identical step was missing from
     * the status page link one screen over, in the slice that wrote both: a
     * rule in one caller is a rule the next caller lacks.
     */
    $this->post('/calendar/feeds', ['name' => 'Everything'])->assertRedirect();

    $this->get('/calendar')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $flashed = $page->toArray()['props']['calendarFeed'];

            expect($flashed)->not->toBeNull()
                ->and($flashed['name'])->toBe('Everything')
                ->and($flashed['url'])->toContain('/calendar/feeds/');
        });

    // Flashed, so the highlight is gone on the next visit rather than
    // following somebody around the screen.
    $this->get('/calendar')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('calendarFeed', null));
});

it('lets somebody who works for two agencies subscribe to both', function (): void {
    /*
     * `Person` and `TeamMembership` exist to tell these apart, and
     * `push_subscriptions` records the same shape one table along: *"a person
     * with two agencies has one phone."*
     *
     * The unique index enforcing *"one live feed per person per subject"*
     * carried no `team_id` while the revoke-on-regenerate query beside it was
     * team-scoped — so the second team's Generate was a unique violation, a
     * 500 with nothing on screen able to explain it, and the row to revoke was
     * on a list S60 does not show. An index has to agree with the query that
     * maintains it.
     */
    $second = Team::factory()->create();

    app(TeamContext::class)->runFor($second, function () use ($second): void {
        app(ProvisionTeam::class)->attachOwner($second, $this->member);
    });

    $mine = $this->feeds->generate($this->team, $this->member, null, 'Bosart Group');

    $theirs = app(TeamContext::class)->runFor(
        $second,
        fn () => $this->feeds->generate($second, $this->member, null, 'The other shop'),
    );

    expect($theirs->token)->not->toBe($mine->token);

    // And both still serve, which is the point of having two.
    foreach ([$mine, $theirs] as $feed) {
        $this->asStranger();
        $this->get($feed->url())->assertOk();
    }

    /*
     * Regenerating one replaces its own team's feed and leaves the other
     * alone — the behaviour the index is there to guarantee, now that the two
     * agree about what "one feed" is scoped to.
     */
    $this->withTeam($this->team);

    $again = $this->feeds->generate($this->team, $this->member, null, 'Bosart Group');

    expect($again->token)->not->toBe($mine->token);

    $this->asStranger();
    $this->get($theirs->url())->assertOk();

    $this->asStranger();
    $this->get($mine->url())->assertNotFound();

    $this->asStranger();
    $this->get($again->url())->assertOk();
});

it('stops serving the moment the person is no longer on the team', function (): void {
    /*
     * A feed URL is a bearer token in somebody's calendar app, and nobody
     * revoking a colleague's access is going to think of their calendar
     * subscription. Without a membership predicate, a person who left in March
     * goes on fetching every showing and every closing date the team has, from
     * a URL nobody remembers exists — `live()` asks only whether the *feed*
     * was revoked.
     *
     * Revocation only, matching `Notification::scopeForPerson()`: reading more
     * strictly than the app writes would cut off somebody who still works
     * there and holds a role composed without a team-surface permission.
     */
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'title' => 'Open house',
        'starts_at' => CarbonImmutable::now()->addDays(3),
    ]);

    $token = feedToken();

    $this->asStranger();

    $this->get("/calendar/feeds/{$token}.ics")->assertOk();

    TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $this->member->getKey())
        ->update(['revoked_at' => now()]);

    $this->asStranger();

    $this->get("/calendar/feeds/{$token}.ics")->assertNotFound();
});

it('stops serving the moment the person stops holding calendar.view', function (): void {
    /*
     * #194. The membership predicate above answers *"did they leave"*; this
     * one answers *"can they still open the calendar"*, and until the decision
     * on #194 nothing asked it. A person moved onto a narrower composed role
     * (S75 makes that two clicks), or a departing agent whose roles are
     * stripped while the membership is left in place for a handover, met a 403
     * on the screen and kept a live `.ics` in Google delivering every showing,
     * every closing date and every event title the team has.
     *
     * The composed role deliberately holds `deals.view` — a **team-surface**
     * permission — so `carryingAccess()` is still true of this membership and
     * the older predicate cannot be what produces the 404. The key is
     * `calendar.view`, and nothing wider.
     */
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'title' => 'Open house',
        'starts_at' => CarbonImmutable::now()->addDays(3),
    ]);

    $token = feedToken();

    $this->asStranger();
    $serving = $this->get("/calendar/feeds/{$token}.ics");

    $serving->assertOk();

    expect($serving->getContent())->toContain('Open house');

    $membership = app(TeamContext::class)->runFor($this->team, function (): TeamMembership {
        $membership = TeamMembership::query()
            ->where('person_id', $this->member->getKey())
            ->sole();

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'deals_only_calendar_feed',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [Permissions::VIEW_DEALS])->pluck('id')->all(),
        );

        $membership->roles()->sync([$role->getKey()]);

        return $membership;
    });

    /*
     * Asserted rather than asserted *about*: the paragraph above claims the
     * older predicate cannot be what produces the 404, and this is the line
     * that makes the claim checkable. Both of them still hold — the person is
     * on the team and reaches the app — so `calendar.view` is the only thing
     * that changed.
     */
    app(TeamContext::class)->runFor($this->team, function (): void {
        expect(TeamMembership::query()
            ->where('person_id', $this->member->getKey())
            ->carryingAccess()
            ->exists())->toBeTrue()
            ->and(TeamMembership::query()
                ->where('person_id', $this->member->getKey())
                ->whereNull('revoked_at')
                ->exists())->toBeTrue();
    });

    /*
     * The control, and the reason this is the right key: the screen refuses
     * them. A feed that outlives that refusal is the whole defect.
     */
    $this->actingAsPerson($this->member, $this->team);
    $this->get('/calendar')->assertForbidden();

    $this->asStranger();
    $this->get("/calendar/feeds/{$token}.ics")->assertNotFound();

    /*
     * And it **gates rather than revokes**. Nothing was written to
     * `calendar_feeds`, so restoring the permission restores the subscription
     * already sitting in somebody's calendar — a role edited by mistake costs
     * a fetch interval, not every URL the team has issued. Revoking is still
     * the deliberate act on S60, and still immediate, which the test above
     * holds.
     */
    app(TeamContext::class)->runFor($this->team, function () use ($membership): void {
        $membership->roles()->sync([
            Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole()->getKey(),
        ]);
    });

    $this->asStranger();
    $again = $this->get("/calendar/feeds/{$token}.ics");

    $again->assertOk();

    expect($again->getContent())->toContain('Open house');
});

it('leaves the street off a single-deal feed, where it says nothing', function (): void {
    /*
     * The suffix exists so *"Deadline: Closing"* says which house on a feed
     * that mixes deals. On a feed scoped to one deal every entry is that
     * house, so the street is repeated on every line and identifies nothing
     * the subscriber does not already know — while still being an address
     * published to whatever server they subscribed from.
     *
     * Which makes the single-deal feed the one to hand to somebody outside the
     * team, and `resources/help/calendar.md` says so.
     */
    app(TeamContext::class)->runFor($this->team, function (): void {
        $link = app(PropertyDeals::class)->link(
            Property::factory()->create([
                'team_id' => $this->team->getKey(),
                'street' => '88 Marberry Rd',
            ]),
            $this->deal,
        );

        app(PropertyDeals::class)->promote($link);
    });

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => CarbonImmutable::now()->addDays(15)->toDateString(),
    ]);

    $everything = feedToken();
    $justThis = feedToken($this->deal);

    $this->asStranger();

    expect($this->get("/calendar/feeds/{$everything}.ics")->getContent())
        ->toContain('88 Marberry Rd');

    $this->asStranger();

    $body = $this->get("/calendar/feeds/{$justThis}.ics")->getContent();

    expect($body)->toContain('Deadline: Closing')
        ->and($body)->not->toContain('88 Marberry Rd');
});

it('never puts a client’s surname in a document Google keeps', function (): void {
    /*
     * `PushPayload`'s rule (#103), one surface along. `Deal::displayName()`
     * falls back to `generated_name`, and `NameDeal` derives that from the
     * client's surname when a deal has no subject property — which is every
     * buy-side deal before an offer. The obvious suffix would publish it.
     *
     * The control is the second half: with a subject property, the street
     * *does* appear, so this cannot pass by the suffix having been dropped
     * altogether.
     */
    $client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Rae',
        'last_name' => 'Zellweger',
    ]);

    /*
     * A deal of its own, with no subject property and no factory-supplied
     * `generated_name` — which is the buy-side deal before an offer that
     * `NameDeal` names after the client.
     */
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'generated_name' => null,
        // Buy side, because `DealRoster::expectedRoles()` is what decides
        // whose surname `NameDeal` reaches for, and on a sale it is the seller.
        'deal_type_id' => DealType::factory()->create([
            'team_id' => $this->team->getKey(),
            'side' => DealSide::Buy,
        ])->getKey(),
    ]);

    app(DealRoster::class)->add($deal, $client, ParticipantRole::Buyer, isPrimary: true);

    app(NameDeal::class)->refresh($deal->fresh());

    expect($deal->fresh()->displayName())->toContain('Zellweger');

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
        'name' => 'Closing',
        'date' => CarbonImmutable::now()->addDays(20)->toDateString(),
    ]);

    /*
     * The **everything** feed, because that is the one that carries a suffix
     * at all: a single-deal feed drops it, every entry on it being the same
     * property. So this is the feed where a surname could have got out.
     */
    $token = feedToken();

    $this->asStranger();

    $body = $this->get("/calendar/feeds/{$token}.ics")->getContent();

    expect($body)->toContain('Deadline: Closing')
        ->and($body)->not->toContain('Zellweger');

    /*
     * The control: a subject property, and the street *is* carried — so this
     * cannot pass by the suffix having been dropped altogether. Linking is the
     * agent's act, so it happens in the agent's team; `asStranger()` above
     * left none resolved, which is the calendar client's situation.
     */
    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        $link = app(PropertyDeals::class)->link(
            Property::factory()->create([
                'team_id' => $this->team->getKey(),
                'street' => '4120 Ivywood Ln',
            ]),
            $deal->fresh(),
        );

        /*
         * Promoted, because a buy-side link is a **candidate** — `link()`
         * refuses to make one the subject on its own, since a buyer looking at
         * six houses has no subject until they choose. Which is also why the
         * half above is the ordinary case rather than an edge: a buy-side deal
         * has no street to publish for as long as the search lasts.
         */
        app(PropertyDeals::class)->promote($link);
    });

    $this->asStranger();

    expect($this->get("/calendar/feeds/{$token}.ics")->getContent())
        ->toContain('4120 Ivywood Ln');
});

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

    $this->asStranger();

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

    $this->asStranger();

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

    $this->asStranger();

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

    $this->asStranger();

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

    $this->asStranger();

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

    $this->asStranger();

    $body = $this->get("/calendar/feeds/{$token}.ics")->assertOk()->getContent();

    expect($body)->toContain('On the subject deal')
        ->and($body)->not->toContain('On another deal');
});

it('stops the moment a feed is revoked', function (): void {
    $issued = $this->feeds->generate($this->team, $this->member, null, 'Everything');

    $this->asStranger();

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
    $this->asStranger();

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

    $this->asStranger();

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

    $this->asStranger();

    $body = $this->get("/calendar/feeds/{$theirs}.ics")->assertOk()->getContent();

    /*
     * The token establishes exactly one tenant, and the render runs inside
     * `runFor()` on it — so the board query is scoped exactly as any screen's
     * is. #42's requirement, on the one reader with no session.
     */
    expect($body)->toContain('Their showing')
        ->and($body)->not->toContain('Our showing');
});
