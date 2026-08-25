<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Person;
use App\Models\Team;
use App\Support\Tenancy\TeamContext;
use Database\Seeders\PerformanceFixtureSeeder;

/**
 * The bar Emily set, measured (PRD §9, §12.2 · issue #89).
 *
 * > Dashboard and deal pages render **under 400ms server-side at p95**, with
 * > **25 active deals and 500 past clients per team**, and **2,000 activity
 * > events**.
 *
 * ## Why this is not the same test as the budgets beside it
 *
 * Every other file in `tests/Performance` asks *"does this page's query count
 * grow with its rows"* against a small fixture shaped for one screen. That is
 * the right thing to gate CI on: query count catches an N+1, and it catches it
 * on a laptop, in a container, and on a shared runner alike, where wall-clock
 * catches whatever else was running.
 *
 * It is not Emily's question. Hers is about a real database at real volume,
 * and #89's definition of done names both — *"query-count budgets are enforced
 * in CI"* and *"all four screens meet the p95 target against the fixture"*.
 *
 * ## Reported, and gated only against catastrophe
 *
 * #89's scope says the timing check is *"reported rather than gating, so the
 * trend is visible"*, and the reason is that a shared CI runner's wall-clock
 * is noisy enough that a 400ms assertion would fail for reasons no commit
 * caused — and a test that fails for reasons nobody caused is a test people
 * learn to re-run.
 *
 * So the number is **printed** and the assertion is `CEILING`, which is an
 * order of magnitude above the target. A screen at 380ms passes quietly; one
 * at 5s fails, and that is a regression no runner noise explains. The p95
 * judgement stays a human one against the printed numbers, which is what
 * "reported" means.
 *
 * A single request is not a p95 either, and pretending otherwise would be the
 * dishonest version of this test. `SAMPLES` requests per screen and the
 * slowest is reported: with four samples the worst is a rough p75, which is
 * the most an assertion this cheap can honestly claim, and it is stated here
 * rather than in the variable name.
 */

/** Ten times the target. A regression this size is not runner noise. */
const CEILING_MS = 4000;

/** Enough to see a slow outlier without turning the suite into a benchmark. */
const SAMPLES = 4;

beforeEach(function (): void {
    $this->seed(PerformanceFixtureSeeder::class);

    $this->team = Team::query()->where('slug', 'perf-team')->sole();

    $person = Person::query()->where('email', 'perf@example.test')->sole();

    $this->actingAsPerson($person, $this->team);
});

/**
 * The slowest of `SAMPLES` requests, in milliseconds.
 */
function slowestRender(string $url): float
{
    $slowest = 0.0;

    for ($i = 0; $i < SAMPLES; $i++) {
        $started = hrtime(true);

        test()->get($url)->assertOk();

        $slowest = max($slowest, (hrtime(true) - $started) / 1_000_000);
    }

    return $slowest;
}

it('renders G8’s four screens inside the budget', function (): void {
    $deal = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Deal => Deal::query()->orderBy('name')->firstOrFail(),
    );

    $screens = [
        'S10 dashboard' => '/dashboard',
        'S13 deals index' => '/deals',
        'S11 my work' => '/work',
        'S15 deal overview' => '/deals/'.$deal->getKey(),
    ];

    $report = [];

    foreach ($screens as $name => $url) {
        $ms = slowestRender($url);

        $report[] = sprintf('%-18s %7.1f ms', $name, $ms);

        expect($ms)->toBeLessThan(
            CEILING_MS,
            "{$name} took {$ms}ms against G8's fixture, which is past the point "
            .'runner noise explains. PRD §9 wants 400ms p95.',
        );
    }

    /*
     * Printed rather than asserted, which is the whole point of the "reported"
     * half of #89's scope: the trend is what a human reads, and a number
     * nobody can see is a measurement nobody has.
     */
    fwrite(STDERR, "\n  G8 render times (slowest of ".SAMPLES.", target 400ms p95):\n    "
        .implode("\n    ", $report)."\n");
});

it('has G8’s volumes behind those numbers', function (): void {
    /*
     * The guard on the guard. A timing test against an empty database is the
     * fastest test in the suite and means nothing — and a fixture that
     * silently stopped seeding would make every number above look like an
     * improvement.
     */
    app(TeamContext::class)->runFor($this->team, function (): void {
        expect(Deal::query()->count())->toBe(25)
            ->and(App\Models\TeamMembership::query()->where('status', 'past_client')->count())->toBe(500)
            ->and(App\Models\ActivityEvent::query()->count())->toBeGreaterThanOrEqual(2000)
            // Mid-flight, not all on stage one: the screens that walk the
            // stages behind the current one only pay for them if there are any.
            ->and(App\Models\Stage::query()->where('state', 'complete')->count())->toBeGreaterThan(0)
            ->and(App\Models\Task::query()->whereNull('completed_at')->count())->toBeGreaterThan(0);
    });
});
