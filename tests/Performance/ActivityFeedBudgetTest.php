<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Team;
use App\Queries\ActivityFeed;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S12 and S31's query budgets (PRD §9's 500,000-event target · issue #81).
 *
 * The feed's whole job is names: the actor, the person a call was logged
 * against, the property, the deal. Every one of them is a lookup a `map()`
 * would make per row, so this is the shape most likely to grow an N+1 — and
 * S31 *had* one. `$event->actor?->displayNameWithin($event->team)` inside a
 * `map()` costs a `teams` lookup **and** a `team_memberships` lookup a row,
 * which is a hundred extra queries on a fifty-event timeline.
 *
 * What catches that is not a ceiling, it is the comparison: the same screen,
 * ten times the rows, the same number of queries.
 */

/**
 * A team with a timeline of a given size, and a variety of subjects — a
 * feed of fifty identical rows would not exercise the per-subject lookups
 * that are the thing at risk.
 *
 * Uniquely named: Pest shares top-level functions across every file in a
 * suite, and `seedDirectory` already exists next door.
 *
 * Returns the team, somebody who can sign in, and the membership id of the
 * one person every other event is subjected to — S31 needs a timeline that
 * grows with `$count`, and a fixture where each person owns exactly one event
 * would render one row at every size and catch nothing.
 *
 * @return array{0: Team, 1: Person, 2: string}
 */
function seedActivityTimeline(int $count, string $prefix = 'actor'): array
{
    /** @var Tests\TestCase $test */
    $test = test();

    [$team, $member] = $test->teamWithMember();

    $clientMembershipId = (string) Str::ulid();
    $clientPersonId = (string) Str::ulid();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $prefix, $clientMembershipId, $clientPersonId): void {
        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
        ]);

        $now = now();

        // The person half the timeline is about.
        $people = [[
            'id' => $clientPersonId,
            'is_super_admin' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]];

        $memberships = [[
            'id' => $clientMembershipId,
            'team_id' => $team->getKey(),
            'person_id' => $clientPersonId,
            'first_name' => 'Claire',
            'last_name' => 'Nakamura',
            'email' => "{$prefix}-client@example.test",
            'status' => PersonLifecycleState::Active->value,
            'is_vendor' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]];

        $events = [];

        foreach (range(1, $count) as $index) {
            $personId = (string) Str::ulid();

            $people[] = [
                'id' => $personId,
                'is_super_admin' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $memberships[] = [
                'id' => (string) Str::ulid(),
                'team_id' => $team->getKey(),
                'person_id' => $personId,
                'first_name' => 'Person'.$index,
                'last_name' => 'Surname'.$index,
                'email' => "{$prefix}{$index}@example.test",
                'status' => PersonLifecycleState::Active->value,
                'is_vendor' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            /*
             * A different **actor** every row, which is what makes a per-row
             * name lookup show up, and a subject that alternates between the
             * client and the actor so the subject lookup varies too. Half the
             * rows carry the deal, so that lookup is exercised as well.
             */
            $events[] = [
                'id' => (string) Str::ulid(),
                'team_id' => $team->getKey(),
                'subject_type' => (new Person)->getMorphClass(),
                'subject_id' => $index % 2 === 0 ? $clientPersonId : $personId,
                'actor_person_id' => $personId,
                'deal_id' => $index % 2 === 0 ? $deal->getKey() : null,
                'event_type' => 'contact.logged',
                'source' => 'manual',
                'occurred_at' => $now->copy()->subMinutes($count - $index),
                'summary' => 'Phone call',
                'payload' => json_encode(['contact_type' => 'phone_call']),
                'is_client_visible' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($people, 100) as $chunk) {
            DB::table('people')->insert($chunk);
        }

        foreach (array_chunk($memberships, 100) as $chunk) {
            DB::table('team_memberships')->insert($chunk);
        }

        foreach (array_chunk($events, 100) as $chunk) {
            DB::table('activity_events')->insert($chunk);
        }
    });

    return [$team, $member, $clientMembershipId];
}

function countActivityQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow the feed’s query count with the page size', function (): void {
    /*
     * Both fixtures built before either is counted. Building the second one
     * inside a running listener would count its inserts against the first
     * measurement, and the two numbers would stop being comparable.
     */
    [$smallTeam, $smallMember] = seedActivityTimeline(4, 'small');
    [$largeTeam, $largeMember] = seedActivityTimeline(ActivityFeed::PER_PAGE, 'large');

    $this->actingAsPerson($smallMember, $smallTeam);
    $small = countActivityQueries(fn () => $this->get('/activity')->assertOk());

    $this->actingAsPerson($largeMember, $largeTeam);
    $large = countActivityQueries(fn () => $this->get('/activity')->assertOk());

    expect($large)->toBe($small);
});

it('does not grow the person timeline’s query count with the timeline', function (): void {
    // S31, which is where the N+1 actually was.
    [$smallTeam, $smallMember, $smallSubject] = seedActivityTimeline(4, 'psmall');
    [$largeTeam, $largeMember, $largeSubject] = seedActivityTimeline(40, 'plarge');

    $this->actingAsPerson($smallMember, $smallTeam);
    $small = countActivityQueries(
        fn () => $this->get("/people/{$smallSubject}")->assertOk(),
    );

    $this->actingAsPerson($largeMember, $largeTeam);
    $large = countActivityQueries(
        fn () => $this->get("/people/{$largeSubject}")->assertOk(),
    );

    expect($large)->toBe($small);
});

it('never puts more than a page of events in the response', function (): void {
    [$team, $member] = seedActivityTimeline(ActivityFeed::PER_PAGE * 3);

    $this->actingAsPerson($member, $team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', ActivityFeed::PER_PAGE)
            ->where('nextCursor', fn (?string $cursor): bool => $cursor !== null));
});
