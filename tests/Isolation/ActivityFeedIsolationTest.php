<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Team;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\TeamContext;

/**
 * S12 and S26 across the tenant boundary (PRD §8.2 · ADR 0002 · issue #81).
 *
 * The feed is the widest read in the product: one screen showing every kind of
 * event a team has produced. A gap here would not leak one record, it would
 * leak the whole timeline — so it gets its own file rather than a case
 * appended to somebody else's.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    [$this->otherTeam, $this->otherMember] = $this->teamWithMember();
});

function isolationDeal(Team $team): Deal
{
    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
    ]));
}

it('never shows one team another team’s activity', function (): void {
    app(TeamContext::class)->runFor($this->otherTeam, fn () => app(RecordActivity::class)->record(
        subject: $this->otherMember,
        eventType: 'contact.logged',
        summary: 'Phone call about 4 Privet Drive',
        source: ActivitySource::Manual,
    ));

    // The row exists — otherwise this test passes on an empty table, which is
    // exactly what a broken scope would also produce.
    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 0));
});

it('refuses to attach a contact to another team’s deal', function (): void {
    $theirDeal = isolationDeal($this->otherTeam);

    $this->actingAsPerson($this->member, $this->team);

    $membership = $this->member->membershipIn($this->team);

    $this->post("/people/{$membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'deal_id' => $theirDeal->getKey(),
    ])->assertSessionHasErrors('deal_id');

    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('never offers another team’s people to the shell’s modal', function (): void {
    $theirMembership = $this->otherMember->membershipIn($this->otherTeam);

    app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn () => $theirMembership?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $mine = $this->member->membershipIn($this->team);

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $mine?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $this->actingAsPerson($this->member, $this->team);

    $response = $this->getJson('/people/candidates?q=Zebediah')->assertOk();

    /*
     * Both teams now hold a Zebediah, which is what stops this asserting on an
     * empty list. A broken endpoint returning nothing at all would pass "no
     * foreign candidates" and fail here.
     */
    expect($response->json('candidates'))->toHaveCount(1)
        ->and($response->json('candidates.0.id'))->toBe($mine?->getKey());
});

it('never leaks a deal name through the feed’s deal label', function (): void {
    // The one column #81 added, exercised the way a bug would exercise it: an
    // event in team A whose `deal_id` names a deal in team B. The composite
    // foreign key refuses the row outright, which is ADR 0002 layer 2 doing
    // the work a scope alone could not.
    $theirDeal = isolationDeal($this->otherTeam);

    app(TeamContext::class)->runFor($this->team, function () use ($theirDeal): void {
        expect(fn () => ActivityEvent::factory()->create([
            'team_id' => $this->team->getKey(),
            'subject_type' => (new Person)->getMorphClass(),
            'subject_id' => $this->member->getKey(),
            'deal_id' => $theirDeal->getKey(),
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });
});
