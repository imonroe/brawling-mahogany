<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * S34 — the vendor directory (PRD §4.2 F2.6, §5.9 · #83).
 *
 * PRD §5.9's fourth step is the whole value of this screen: *"filtering the
 * directory by specialty surfaces him with his rating and history."* A
 * directory that cannot be asked *"who stages, in this area, that we liked"*
 * is a contact list, so the filters are what is tested here — along with the
 * one column F2.6 singles out as most likely to be stale if it were stored.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

function vendor(string $first, array $attributes = []): TeamMembership
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team, $first, $attributes): TeamMembership {
        $person = Person::factory()->create();

        return TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => $first,
            'last_name' => 'Vendor',
            'is_vendor' => true,
            'joined_at' => now(),
            ...$attributes,
        ]);
    });
}

it('narrows the directory to who does the thing', function (): void {
    vendor('Sam', ['vendor_specialties' => ['staging', 'photography']]);
    vendor('Pat', ['vendor_specialties' => ['inspection']]);

    $this->get('/people?segment=vendors&specialty=staging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = collect($page->toArray()['props']['people']['data'])->pluck('firstName');

            expect($names)->toContain('Sam')->not->toContain('Pat');
        });
});

it('matches a specialty as a tag, not as a substring of something else', function (): void {
    // `jsonb` containment, so a vendor whose *service area* mentions staging
    // is not a stager.
    vendor('Sam', ['vendor_specialties' => ['staging']]);
    vendor('Pat', [
        'vendor_specialties' => ['inspection'],
        'vendor_service_area' => 'Staging area, north Denver',
    ]);

    $this->get('/people?segment=vendors&specialty=staging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = collect($page->toArray()['props']['people']['data'])->pluck('firstName');

            expect($names->all())->toBe(['Sam']);
        });
});

it('narrows by service area too', function (): void {
    vendor('Sam', ['vendor_service_area' => 'Denver metro']);
    vendor('Pat', ['vendor_service_area' => 'Boulder county']);

    $this->get('/people?segment=vendors&area=denver')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = collect($page->toArray()['props']['people']['data'])->pluck('firstName');

            expect($names)->toContain('Sam')->not->toContain('Pat');
        });
});

it('derives last used from real deal participation', function (): void {
    /*
     * F2.6: *"the most useful column and the one most likely to be stale if
     * duplicated."* So it is a subquery over `deal_participants` rather than a
     * column somebody has to remember to write.
     */
    $sam = vendor('Sam', ['vendor_specialties' => ['staging']]);
    $pat = vendor('Pat', ['vendor_specialties' => ['staging']]);

    app(TeamContext::class)->runFor($this->team, function () use ($sam): void {
        $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

        // `deal_id` and `team_membership_id` are not fillable — a request
        // body must not choose either — so the fixture forces them.
        DealParticipant::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'team_membership_id' => $sam->getKey(),
            'participant_role' => ParticipantRole::Stager,
        ]);
    });

    unset($pat);

    $this->get('/people?segment=vendors')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $rows = collect($page->toArray()['props']['people']['data'])->keyBy('firstName');

            expect($rows['Sam']['vendor']['lastUsedAt'])->not->toBeNull()
                // Never engaged is a fact about this team, not a missing field.
                ->and($rows['Pat']['vendor']['lastUsedAt'])->toBeNull();
        });
});

it('offers only the specialties this team has actually typed', function (): void {
    // IA §13.3 made specialties free text, so there is no lookup to seed and
    // the honest list is the one in use.
    vendor('Sam', ['vendor_specialties' => ['staging', 'photography']]);
    vendor('Pat', ['vendor_specialties' => ['photography']]);

    $this->get('/people?segment=vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('specialties', ['photography', 'staging']));
});

it('ignores a vendor filter on a segment that has none', function (): void {
    /*
     * A stale `?specialty=` in a bookmark must not empty the Clients tab —
     * "staging" is not a thing to narrow a client list by, so the filter is
     * ignored rather than applied or refused.
     */
    $this->post('/people', [
        'first_name' => 'Claire',
        'last_name' => 'Nakamura',
        'status' => 'active',
    ])->assertRedirect();

    $this->get('/people?segment=clients&specialty=staging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = collect($page->toArray()['props']['people']['data'])->pluck('firstName');

            expect($names)->toContain('Claire');
        });
});

it('lets a vendor be a past client at the same time', function (): void {
    /*
     * IA §13.3's decision from #48, and the reason `is_vendor` is a flag: *"a
     * stager can be a past client and a vendor at once, which one status
     * column cannot express."*
     */
    $sam = vendor('Sam', [
        'vendor_specialties' => ['staging'],
        'status' => App\Enums\PersonLifecycleState::PastClient,
    ]);

    foreach (['vendors', 'clients'] as $segment) {
        $this->get("/people?segment={$segment}")
            ->assertOk()
            ->assertInertia(function ($page) use ($sam): void {
                $ids = collect($page->toArray()['props']['people']['data'])->pluck('id');

                expect($ids)->toContain($sam->getKey());
            });
    }
});
