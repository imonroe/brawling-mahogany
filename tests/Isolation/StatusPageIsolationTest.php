<?php

declare(strict_types=1);

use App\Enums\DocumentVisibility;
use App\Models\Deal;
use App\Models\Document;
use App\Models\StatusPageLink;
use App\Models\TeamMembership;
use App\Support\StatusPage\DispatchStatusPageLink;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Mail;

/**
 * The client surface, across the tenant boundary (PRD §8.2 · #42, #110).
 *
 * #110's definition of done: *"tokens are opaque, single-use, and scoped to
 * one deal — proven in #42."* This is that proof, and it matters more here
 * than anywhere else in the product: every other reader has a session and a
 * resolved team, and this one has a string in a URL.
 *
 * ADR 0002 records the exception the whole feature rests on — the token is
 * what establishes the tenant — so what is asserted below is that the token
 * establishes **exactly one**, and that nothing downstream widens it.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    [$this->otherTeam, $this->stranger] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'dana@example.test',
    ]);
});

/** A live session on this team's deal, and the token that opens it. */
function clientSession(): string
{
    $issued = app(IssueStatusPageLink::class)->issue(test()->deal, test()->client);

    auth()->logout();

    return (string) str(
        test()->get('/s/'.$issued->token)->headers->get('Location'),
    )->afterLast('/s/');
}

it('reaches nothing belonging to another team', function (): void {
    $session = clientSession();

    $otherDeal = app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn (): Deal => Deal::factory()->create(['team_id' => $this->otherTeam->getKey()]),
    );

    $otherDocument = app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn (): Document => Document::factory()->create([
            'team_id' => $this->otherTeam->getKey(),
            'documentable_type' => (new Deal)->getMorphClass(),
            'documentable_id' => $otherDeal->getKey(),
            'visibility' => DocumentVisibility::ClientVisible,
        ]),
    );

    /*
     * A live session, a real client-visible document id, and the wrong team.
     * The controller narrows by hand — the link's own `team_id`, the deal that
     * link names, and `client_visible` — because there is no global scope in
     * play for a request with no tenant.
     */
    $this->get("/s/{$session}/documents/{$otherDocument->getKey()}")->assertNotFound();
});

it('never lets another team’s token open this team’s deal', function (): void {
    $theirs = app(TeamContext::class)->runFor($this->otherTeam, function (): string {
        $deal = Deal::factory()->create(['team_id' => $this->otherTeam->getKey()]);

        $membership = TeamMembership::factory()->create([
            'team_id' => $this->otherTeam->getKey(),
        ]);

        return app(IssueStatusPageLink::class)->issue($deal, $membership)->token;
    });

    auth()->logout();

    $redirect = $this->get("/s/{$theirs}");

    $session = (string) str($redirect->headers->get('Location'))->afterLast('/s/');

    /*
     * It opens — their client's own page, which is correct — and what it
     * opens is *their* deal. The token establishes one tenant, and the page
     * it renders is the one that token names.
     */
    $props = [];

    $this->get("/s/{$session}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    expect(json_encode($props))->not->toContain((string) $this->deal->getKey());
});

it('re-issues a link only for a grant the address already had', function (): void {
    Mail::fake();

    /*
     * S64's escape hatch starts from an email address and nothing else, so it
     * is the one place in the product that reaches across tenants by design.
     * What keeps it safe is that it matches **existing grants** rather than
     * the people directory: asking cannot get anybody onto a deal, only back
     * onto one they were already on.
     */
    app(TeamContext::class)->runFor($this->otherTeam, function (): void {
        TeamMembership::factory()->create([
            'team_id' => $this->otherTeam->getKey(),
            // The same address, in a team that has never given them a link.
            'email' => 'dana@example.test',
        ]);
    });

    app(IssueStatusPageLink::class)->issue($this->deal, $this->client);

    $sent = app(DispatchStatusPageLink::class)->forAddress('dana@example.test');

    expect($sent)->toBe(1);

    Mail::assertSentCount(1);
});

it('does not hand back access an agent has revoked', function (): void {
    Mail::fake();

    $issued = app(IssueStatusPageLink::class)->issue($this->deal, $this->client);

    app(IssueStatusPageLink::class)->revoke($issued->link);

    /*
     * An agent who took somebody's access away must not have it handed back by
     * an endpoint anybody can hit. Expired and used grants are re-issued —
     * those are exactly the people this exists for — and revoked ones are not.
     */
    expect(app(DispatchStatusPageLink::class)->forAddress('dana@example.test'))->toBe(0);

    Mail::assertNothingSent();
});

it('scopes one grant to one deal', function (): void {
    $secondDeal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $session = clientSession();

    $document = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Document => Document::factory()->create([
            'team_id' => $this->team->getKey(),
            'documentable_type' => (new Deal)->getMorphClass(),
            'documentable_id' => $secondDeal->getKey(),
            'visibility' => DocumentVisibility::ClientVisible,
        ]),
    );

    // Same team, same client, different deal. A grant is per deal.
    $this->get("/s/{$session}/documents/{$document->getKey()}")->assertNotFound();
});

it('gives every issue a token that shares nothing with the last', function (): void {
    $first = app(IssueStatusPageLink::class)->issue($this->deal, $this->client);
    $second = app(IssueStatusPageLink::class)->issue($this->deal, $this->client);

    /*
     * F7.7: *"nothing sequential, nothing guessable, nothing that leaks how
     * many deals exist."* And issuing revokes what came before, so there is
     * never a second working URL somebody forgot about.
     */
    expect($first->token)->not->toBe($second->token)
        ->and($first->link->refresh()->isRevoked())->toBeTrue()
        ->and(StatusPageLink::query()->live()->count())->toBe(1);
});
