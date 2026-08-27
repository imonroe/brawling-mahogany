<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\StatusPageLink;
use App\Models\TeamMembership;
use App\Support\StatusPage\IssueStatusPageLink;
use Inertia\Testing\AssertableInertia;

/**
 * S61, S62, S63 and S64 (PRD §4.7 · IA §9 · issues #110, #111).
 *
 * The definition of done, as assertions: the link works first try with no
 * password; expired, used and revoked each render their own screen; the tokens
 * are opaque and scoped to one deal; every issuance and use writes an activity
 * event; and no internal vocabulary reaches the page.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'email' => 'dana@example.test',
    ]);

    DealParticipant::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'team_membership_id' => $this->client->getKey(),
        'participant_role' => ParticipantRole::Seller,
        'is_primary' => true,
    ]);

    $this->issue = app(IssueStatusPageLink::class);
});

/** A live link, and the plaintext that only exists here. */
function issuedFor(): array
{
    $issued = app(IssueStatusPageLink::class)->issue(test()->deal, test()->client);

    return [$issued->link, $issued->token];
}

it('opens the page from a link, first try, with no password', function (): void {
    [, $token] = issuedFor();

    /*
     * Signed out entirely. PRD §3.3: a client uses this *"once every seven
     * years… must work on a phone, first try, no password"*, and a test that
     * kept the agent's session would not be testing that at all.
     */
    auth()->logout();

    $redirect = $this->get("/s/{$token}");

    $redirect->assertRedirect();

    $session = (string) str($redirect->headers->get('Location'))->afterLast('/s/');

    expect($session)->not->toBe($token);

    $this->get("/s/{$session}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Status/Show'));
});

it('spends a link once, and the second attempt lands on S64', function (): void {
    [, $token] = issuedFor();

    auth()->logout();

    $this->get("/s/{$token}")->assertRedirect();

    $this->get("/s/{$token}")
        ->assertRedirect('/s/expired?reason=used');
});

it('says which of expired, used and revoked it is', function (): void {
    auth()->logout();

    $cases = [
        'expired' => StatusPageLink::factory()->expired(),
        'used' => StatusPageLink::factory()->used(),
        'revoked' => StatusPageLink::factory()->revoked(),
    ];

    foreach ($cases as $reason => $factory) {
        $token = Illuminate\Support\Str::random(43);

        $factory->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $this->deal->getKey(),
            'team_membership_id' => $this->client->getKey(),
            'token_hash' => StatusPageLink::hashToken($token),
        ]);

        /*
         * Three different sentences to a client. Collapsing them into
         * "invalid" turns a two-second understanding into a phone call, which
         * is the outcome this whole surface exists to reduce.
         */
        $this->get("/s/{$token}")->assertRedirect("/s/expired?reason={$reason}");
    }
});

it('keeps a session working after the link that made it has been spent', function (): void {
    /*
     * #110 asks for this trade to be decided rather than inherited: *"a
     * strictly single-use 30-minute link means a client who reopens the page
     * an hour later is locked out — which is a support call to the agent."*
     */
    [$link, $token] = issuedFor();

    auth()->logout();

    $session = (string) str($this->get("/s/{$token}")->headers->get('Location'))->afterLast('/s/');

    $this->travel(2)->hours();

    // The link is long past its thirty minutes…
    $this->get("/s/{$token}")->assertRedirect('/s/expired?reason=used');

    // …and the session it established still opens the page.
    $this->get("/s/{$session}")->assertOk();

    expect($link->refresh()->session_expires_at?->isFuture())->toBeTrue();
});

it('stops both credentials the moment access is revoked', function (): void {
    [$link, $token] = issuedFor();

    auth()->logout();

    $session = (string) str($this->get("/s/{$token}")->headers->get('Location'))->afterLast('/s/');

    $this->get("/s/{$session}")->assertOk();

    app(IssueStatusPageLink::class)->revoke($link->refresh());

    $this->get("/s/{$session}")->assertRedirect('/s/expired?reason=revoked');
});

it('stores no plaintext token anywhere', function (): void {
    [$link, $token] = issuedFor();

    /*
     * A leaked database dump must not be a set of working keys to every
     * client's transaction — the reason `TeamInvitation` hashes its own.
     */
    expect($link->token_hash)->toBe(hash('sha256', $token))
        ->and($link->token_hash)->not->toBe($token)
        ->and(mb_strlen($token))->toBeGreaterThanOrEqual(40);
});

it('writes an activity event and an audit entry for every issuance and use', function (): void {
    ActivityEvent::query()->delete();

    [, $token] = issuedFor();

    expect(ActivityEvent::query()->where('event_type', 'status_page.link_issued')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'status_page.link_issued')->exists())->toBeTrue();

    auth()->logout();

    $this->get("/s/{$token}");

    expect(ActivityEvent::query()->where('event_type', 'status_page.opened')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'status_page.opened')->exists())->toBeTrue();
});

it('counts repeat visits without writing an entry for each one', function (): void {
    [$link, $token] = issuedFor();

    auth()->logout();

    $session = (string) str($this->get("/s/{$token}")->headers->get('Location'))->afterLast('/s/');

    $this->get("/s/{$session}");
    $this->get("/s/{$session}");

    /*
     * A client refreshing a page four times is one visit, and four timeline
     * entries would bury the advance that happened between them.
     */
    expect($link->refresh()->view_count)->toBe(2)
        ->and($link->last_seen_at)->not->toBeNull()
        ->and(ActivityEvent::query()->where('event_type', 'status_page.opened')->count())->toBe(1);
});

it('carries the headers a URL-borne credential needs', function (): void {
    [, $token] = issuedFor();

    auth()->logout();

    $session = (string) str($this->get("/s/{$token}")->headers->get('Location'))->afterLast('/s/');

    $response = $this->get("/s/{$session}");

    /*
     * The session token is in the path — a deliberate trade — which makes the
     * URL itself the credential. A referrer would hand it to whatever a client
     * clicks through to, and an index would publish somebody's transaction.
     */
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
});
