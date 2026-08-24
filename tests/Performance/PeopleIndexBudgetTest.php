<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Queries\PeopleDirectory;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S30's volume requirement, held rather than hoped for (issue #47).
 *
 * *"The 500-row requirement is real."* PRD §3.4 puts twenty-five active deals
 * and hundreds of past clients in a team, and the people index is the screen
 * that meets that volume first.
 *
 * Two things are asserted, and the second is the one that matters: the query
 * count does not grow with the directory. A per-row query for the person
 * behind each membership is how this screen becomes unusable at exactly the
 * point a customer starts relying on it.
 */

/**
 * A team with a directory of the size PRD §3.4 describes.
 *
 * The prefix keeps two calls in one test from colliding on the partial unique
 * index over `people.email` — a shared person record is the whole point of
 * the schema, so two teams asking for "person1@example.test" is a real
 * collision rather than a fixture quirk.
 */
function seedDirectory(int $count, string $prefix = 'person'): array
{
    /** @var Tests\TestCase $test */
    $test = test();

    [$team, $member] = $test->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $prefix): void {
        $now = now();

        // Inserted directly: five hundred round trips through Eloquent is a
        // slow fixture, and the point of the test is the *read* path.
        $people = [];
        $memberships = [];

        foreach (range(1, $count) as $index) {
            $personId = (string) Str::ulid();

            // A credential-less login row; everything a screen shows lives on
            // the membership below (#140).
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
                'last_name' => 'Surname'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'email' => "{$prefix}{$index}@example.test",
                'phone' => '303555'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'status' => PersonLifecycleState::PastClient->value,
                'is_vendor' => false,
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
    });

    return [$team, $member];
}

it('renders the people index within its query budget at 500 rows', function (): void {
    [$team, $member] = seedDirectory(500);

    $this->actingAsPerson($member, $team);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/people')->assertOk();

    /*
     * Five segment counts, the page, its total, the shared tenancy queries
     * every request carries, and the two below. Not five hundred.
     *
     * **22 rather than 20 since #162**, and the two are worth naming rather
     * than absorbing: the row says whether the lifecycle badge describes this
     * person at all (`carriesAccess`) and carries the role names it is drawn
     * with instead, so `roles` and `roles.permissions` are eager-loaded. Both
     * are one query for the page however many rows it holds — the growth test
     * below is what proves that, and it is the one that would catch this
     * becoming per-row.
     */
    expect($queries)->toBeLessThanOrEqual(22);
});

it('does not grow its query count with the directory', function (): void {
    // The assertion that actually catches an N+1: the same page, ten times
    // the rows, the same number of queries.
    [$smallTeam, $smallMember] = seedDirectory(20, 'small');

    $this->actingAsPerson($smallMember, $smallTeam);

    $small = countQueries(fn () => $this->get('/people')->assertOk());

    [$largeTeam, $largeMember] = seedDirectory(400, 'large');

    $this->actingAsPerson($largeMember, $largeTeam);

    $large = countQueries(fn () => $this->get('/people')->assertOk());

    expect($large)->toBe($small);
});

it('never puts more than a page of rows in the response', function (): void {
    [$team, $member] = seedDirectory(500);

    $this->actingAsPerson($member, $team);

    $this->get('/people')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('people.data', PeopleDirectory::PER_PAGE)
            // Five hundred seeded, plus the owner and the member the team
            // was built with.
            ->where('people.total', 502));
});

it('searches without loading the directory', function (): void {
    [$team, $member] = seedDirectory(500);

    $this->actingAsPerson($member, $team);

    $this->get('/people?search=Surname0473')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('people.data', 1));
});

function countQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}
