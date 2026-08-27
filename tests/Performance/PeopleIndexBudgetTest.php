<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\TeamMembership;
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

        /*
         * **The page must always contain a role-holder**, in every fixture,
         * or the two counts below are not comparable.
         *
         * Eloquent skips a nested eager load when the parent returns nothing:
         * `with('roles.permissions')` costs two queries on a page holding a
         * colleague and **one** on a page of pure contacts. The team's own
         * owner and member are the only role-holders here, and their faker
         * surnames sorted them onto page one of the 20-row fixture and
         * usually off page one of the 500-row fixture — so the growth test
         * compared 22 against 21 and failed about a quarter of the time.
         * Review on #162 caught it by running the suite repeatedly rather than
         * once.
         *
         * Sorting them to the front makes the composition the same on both
         * sides, which is what the test is actually about: the same page, ten
         * times the rows, the same number of queries. A fixture that differs
         * in *what it holds* measures the difference in what it holds.
         */
        TeamMembership::query()
            ->carryingAccess()
            ->get()
            ->each(fn (TeamMembership $membership) => $membership
                ->forceFill(['last_name' => 'Aaa'.$membership->getKey()])
                ->save());

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
     *
     * **23 rather than 22 since #80**, and the extra one is not this screen's
     * at all: Design System §10.4 puts the My Work count in the sidebar beside
     * the link, on every screen, so `HandleInertiaRequests` counts it once per
     * request. A number that is only right on `/work` is wrong everywhere
     * else, which is worse than no number — so the query is the price of the
     * count being true, and it is paid here, on `/deals`, and on everything
     * else wearing the shell.
     *
     * **24 rather than 23 since #93**, and the second shell count is the same
     * bargain made a second time — which is the reason to name it rather than
     * absorb it. S47's approval queue holds client messages that do not go
     * out until somebody releases them, and PRD §4.5 makes that queue a launch
     * blocker; a queue nobody is told about is a set of client emails that
     * silently never send, which is a worse failure than the one the queue
     * prevents because it is invisible. One indexed count against
     * `(team_id, state, scheduled_for)`, once per request, is what makes the
     * badge true everywhere.
     *
     * Two was where the shell's counts stopped being free, and this comment
     * used to say that a third would need a different mechanism — one query
     * returning several counts — rather than a third line in
     * `HandleInertiaRequests`. S08's unread count (#101) was the third, and
     * the budget did exactly what it was written to do: raising the number by
     * one was the easy diff and the wrong one, because a fourth badge would
     * have raised it again.
     *
     * So there are now **three** counts and **one** query — `App\Queries\
     * ShellCounts`, three scalar subqueries in a single round trip. The
     * ceiling comes down by one rather than up, which is the shape a budget
     * should have after a feature lands on the shell.
     */
    expect($queries)->toBeLessThanOrEqual(23);
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

it('puts a colleague on the first page of every fixture', function (): void {
    /*
     * The control for the comparison above, and the reason it is a test.
     *
     * Eloquent skips a nested eager load when the parent returns nothing, so
     * a page of pure contacts costs one query fewer than a page holding a
     * colleague — and the growth test then compares two numbers that differ
     * for a reason that has nothing to do with row count. It failed about a
     * quarter of the time before `seedDirectory()` sorted the role-holders to
     * the front, and a fixture whose composition drifts back would make it
     * flaky again **silently**, since the failure looks like an N+1.
     *
     * So the property the comparison rests on is asserted directly, at both
     * sizes, rather than trusted.
     */
    foreach ([20 => 'ctrl-small', 400 => 'ctrl-large'] as $size => $prefix) {
        [$team, $member] = seedDirectory($size, $prefix);

        $this->actingAsPerson($member, $team);

        $this->get('/people')
            ->assertOk()
            ->assertInertia(function ($page) use ($size): void {
                $colleagues = collect($page->toArray()['props']['people']['data'])
                    ->filter(fn (array $row): bool => $row['isColleague'] === true);

                expect($colleagues)
                    ->not->toBeEmpty("No colleague on page one of the {$size}-row fixture.");
            });
    }
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
