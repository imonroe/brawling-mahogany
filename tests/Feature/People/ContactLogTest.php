<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealType;
use App\Models\Team;
use App\Support\Tenancy\TeamContext;

/**
 * S26 — log a contact (PRD §4.2 F2.5 · issue #81).
 *
 * The endpoint under the two-click modal. What is tested here is the half a
 * component test cannot see: what a submit with only a type in it writes, what
 * attaching a deal does to where the entry shows up, and what "when it
 * happened" means to a team that is not in UTC.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);

    $this->membership = $this->member->membershipIn($this->team);
});

function contactLogDeal(Team $team): Deal
{
    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
    ]));
}

it('saves an entry with nothing but the type', function (): void {
    // The two-click contract, at the endpoint: the modal's second click sends
    // exactly this, and anything else being required would make it a third.
    $this->post("/people/{$this->membership?->getKey()}/contact-log", [
        'contact_type' => 'showing',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $event = ActivityEvent::query()->where('event_type', 'contact.logged')->sole();

    expect($event->summary)->toBe('Showing')
        ->and($event->source)->toBe('manual')
        ->and($event->payload['note'] ?? null)->toBeNull()
        // Unattached, which F2.5 allows: "against a person and optionally a
        // deal."
        ->and($event->deal_id)->toBeNull();
});

it('puts an attached entry on the deal as well as the person', function (): void {
    $deal = contactLogDeal($this->team);

    $this->post("/people/{$this->membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'note' => 'Walked through the inspection dates.',
        'deal_id' => $deal->getKey(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $event = ActivityEvent::query()->where('event_type', 'contact.logged')->sole();

    // The subject stays the person — that is what F2.5 logs against, and what
    // the person record reads. The deal is context, and the deal's own
    // timeline reads that.
    expect($event->subject_id)->toBe($this->member->getKey())
        ->and($event->deal_id)->toBe($deal->getKey())
        ->and(ActivityEvent::query()->forSubject($this->member)->pluck('id'))
        ->toContain($event->getKey())
        ->and(ActivityEvent::query()->forDeal($deal)->pluck('id'))
        ->toContain($event->getKey());
});

it('refuses a deal from another team without touching the timeline', function (): void {
    $other = Team::factory()->create();
    $theirDeal = contactLogDeal($other);

    $this->post("/people/{$this->membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'deal_id' => $theirDeal->getKey(),
    ])->assertSessionHasErrors('deal_id');

    // Nothing written at all — not an entry with the deal quietly dropped,
    // which is what an unscoped `exists` plus a scoped lookup would produce.
    expect(ActivityEvent::query()->where('event_type', 'contact.logged')->count())->toBe(0);
});

it('reads a typed time in the team’s timezone and stores it in UTC', function (): void {
    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $this->team->forceFill(['timezone' => 'America/Denver'])->save(),
    );

    // What a `datetime-local` input sends: wall-clock time, no zone on it.
    $this->post("/people/{$this->membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'occurred_at' => '2026-08-20T09:00',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $event = ActivityEvent::query()->where('event_type', 'contact.logged')->sole();

    // 9am in Denver is 15:00 UTC in August. Parsed as UTC it would have been
    // stored as 09:00 — four in the morning for the team that typed it.
    expect($event->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-08-20 15:00');
});

it('defaults the time to now when nobody typed one', function (): void {
    $this->freezeAt('2026-08-20T17:30:00Z');

    $this->post("/people/{$this->membership?->getKey()}/contact-log", [
        'contact_type' => 'text',
    ])->assertRedirect();

    $event = ActivityEvent::query()->where('event_type', 'contact.logged')->sole();

    expect($event->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-08-20 17:30');
});

it('offers the deals a person is on, and only those', function (): void {
    $theirs = contactLogDeal($this->team);

    // A second deal in the same team that this person has nothing to do with.
    // The modal offering it would make the attachment a search rather than a
    // choice, which is a click the two-click target does not have.
    contactLogDeal($this->team);

    app(TeamContext::class)->runFor($this->team, function () use ($theirs): void {
        DealParticipant::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $theirs->getKey(),
            'team_membership_id' => $this->membership?->getKey(),
            'participant_role' => ParticipantRole::Buyer,
        ]);
    });

    $this->get("/people/{$this->membership?->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals', 1)
            ->where('deals.0.id', $theirs->getKey()));
});

it('finds a person for the shell’s modal, with their deals attached', function (): void {
    $deal = contactLogDeal($this->team);

    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        DealParticipant::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'team_membership_id' => $this->membership?->getKey(),
            'participant_role' => ParticipantRole::Seller,
        ]);
    });

    $response = $this->getJson('/people/candidates?q='.urlencode((string) $this->membership?->first_name));

    $response->assertOk();

    $candidate = collect($response->json('candidates'))
        ->firstWhere('id', $this->membership?->getKey());

    expect($candidate)->not->toBeNull()
        ->and($candidate['name'])->toBe($this->membership?->fullName())
        // The deals travel with the candidate, so picking somebody in the
        // modal does not cost a second round trip before the attachment can
        // be offered.
        ->and($candidate['deals'])->toHaveCount(1)
        ->and($candidate['deals'][0]['id'])->toBe($deal->getKey());
});
