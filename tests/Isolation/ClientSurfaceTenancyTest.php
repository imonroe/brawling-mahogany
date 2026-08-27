<?php

declare(strict_types=1);

use App\Enums\DocumentVisibility;
use App\Enums\ParticipantRole;
use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Event;
use App\Models\KeyDate;
use App\Models\TeamMembership;
use App\Support\Calendar\ManageCalendarFeeds;
use App\Support\Deals\DealRoster;
use App\Support\Documents\DocumentStorage;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\MissingTeamContextException;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Every route a **stranger** reaches, made the way a stranger makes it
 * (issues #108, #110, #111 · ADR 0002).
 *
 * ## Why this file exists, rather than more assertions in the feature tests
 *
 * Slice 4 adds the first two surfaces in the product with no `auth` and no
 * `team` middleware: `/s/{token}` and the `.ics` feed. Both are documented as
 * *"the token establishes the tenant"* — ADR 0002's stated exception — and
 * both shipped without anything that actually established it. Every one of
 * these routes threw `MissingTeamContextException` for a real client, and the
 * whole suite was green.
 *
 * The reason it was green is worth more than the fix. `TestCase::withTeam()`
 * binds a `TeamContext` into the container **before** the request is made, so
 * by the time the pipeline runs there is a team whatever the route resolves —
 * and `auth()->logout()`, which the status page tests were careful to call,
 * clears the guard and not the binding. `bootstrap/app.php` already records
 * this trap from issue #156, where three signed-in routes 500'd in production
 * and passed here. It recurred one slice later on the surface where it costs
 * the most.
 *
 * So the shape of this file is: clear the binding (`asStranger()`), make the
 * request, and assert a **real response**. The first test is the control — if
 * `asStranger()` ever stops clearing the context, every other test below would
 * keep passing while proving nothing, which is exactly the failure it is here
 * to prevent.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Rae',
        'last_name' => 'Okonkwo',
        'email' => 'rae@example.test',
    ]);

    app(DealRoster::class)->add($this->deal, $this->client, ParticipantRole::Seller, isPrimary: true);
});

/** A live session token, the way a client gets one: by pressing the link. */
function tenancyClientSession(): string
{
    $issued = app(IssueStatusPageLink::class)->issue(test()->deal, test()->client);

    test()->asStranger();

    $redirect = test()->get('/s/'.$issued->token);

    $redirect->assertRedirect();

    return (string) str((string) $redirect->headers->get('Location'))->afterLast('/s/');
}

it('leaves no team resolved after asStranger, which every test below depends on', function (): void {
    /*
     * The control. Without it this file could pass in full against a helper
     * that had quietly stopped clearing the context — which is the *original*
     * defect, not a hypothetical one.
     */
    expect(app(TeamContext::class)->has())->toBeTrue();

    $this->asStranger();

    expect(app(TeamContext::class)->has())->toBeFalse();

    // And a scoped read in that state is the exception these routes were
    // throwing. Named, so the connection to the fix is not folklore.
    expect(fn () => Deal::query()->count())
        ->toThrow(MissingTeamContextException::class);
});

it('spends a link and renders the status page for somebody with no session', function (): void {
    $session = tenancyClientSession();

    $this->asStranger();

    $this->get('/s/'.$session)->assertOk();
});

it('lists and serves a client-visible document with no session', function (): void {
    $document = Document::factory()->create([
        'team_id' => $this->team->getKey(),
        'documentable_type' => $this->deal->getMorphClass(),
        'documentable_id' => $this->deal->getKey(),
        'visibility' => DocumentVisibility::ClientVisible,
    ]);

    Storage::disk(DocumentStorage::DISK)->put($document->path, 'bytes');

    $session = tenancyClientSession();

    $this->asStranger();

    $this->get('/s/'.$session.'/documents')->assertOk();

    $this->asStranger();

    $this->get('/s/'.$session.'/documents/'.$document->getKey())->assertOk();
});

it('answers the request-a-new-link form with no session', function (): void {
    Mail::fake();

    app(IssueStatusPageLink::class)->issue($this->deal, $this->client);

    $this->asStranger();

    $this->post('/s/request', ['email' => 'rae@example.test'])
        ->assertRedirect('/s/expired?sent=1');

    Mail::assertSentCount(1);
});

it('renders S64 with no session', function (): void {
    $this->asStranger();

    $this->get('/s/expired?reason=revoked')->assertOk();
});

it('serves a whole-team ics feed to a calendar client', function (): void {
    Event::factory()->create([
        'team_id' => $this->team->getKey(),
        'title' => 'Open house',
        'starts_at' => CarbonImmutable::now()->addDays(2),
    ]);

    $feed = app(ManageCalendarFeeds::class)->generate($this->team, $this->member, null, 'Everything');

    $this->asStranger();

    $this->get($feed->url())
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
});

it('serves a per-deal ics feed, which loads a scoped relation to answer at all', function (): void {
    /*
     * Separately from the feed above, because the two failed at different
     * points: a whole-team feed carries a null `deal_id` and eager-loads
     * nothing, while a per-deal feed ran a `Deal` query inside the lookup —
     * before the line that establishes the tenant had run.
     */
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => CarbonImmutable::now()->addDays(30)->toDateString(),
    ]);

    $feed = app(ManageCalendarFeeds::class)->generate($this->team, $this->member, $this->deal, 'This deal');

    $this->asStranger();

    $body = $this->get($feed->url())->assertOk()->getContent();

    expect($body)->toContain('Deadline: Closing');
});

it('counts the fetch, which is a scoped write on a request with no team', function (): void {
    $feed = app(ManageCalendarFeeds::class)->generate($this->team, $this->member, null, 'Everything');

    $this->asStranger();

    $this->get($feed->url())->assertOk();

    app(TeamContext::class)->runFor($this->team, function () use ($feed): void {
        expect(CalendarFeed::query()->whereKey($feed->getKey())->sole()->fetch_count)->toBe(1);
    });
});
