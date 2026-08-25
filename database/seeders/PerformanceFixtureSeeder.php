<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivitySource;
use App\Enums\DealState;
use App\Enums\PersonLifecycleState;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\GateTemplate;
use App\Models\Person;
use App\Models\Role;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Deals\DealTasks;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * G8's volumes, in a database somebody can open (PRD §9, §12.2 · issue #89).
 *
 * PRD §9, which §12.2 repeats and notes *"Emily set this bar explicitly"*:
 *
 * > Dashboard and deal pages render **under 400ms server-side at p95**, with
 * > **25 active deals and 500 past clients per team**, and **2,000 activity
 * > events**.
 *
 * ## Why this exists beside the budget tests
 *
 * The `tests/Performance` suite builds its own fixtures, small and shaped for
 * one screen each, and asks *"does this page's query count grow with its
 * rows"* — which is the question that catches an N+1 and the right one to gate
 * CI on. It is not the question Emily asked. Hers is about a real database at
 * real volume, and the only honest way to answer it is to have one and look.
 *
 * #89's definition of done names both: *"fixture exists and is usable
 * locally"* and *"all four screens meet the p95 target against the fixture"*.
 * This is the first; `tests/Performance/G8TimingTest.php` is the second.
 *
 * ## Run it
 *
 * ```
 * php artisan db:seed --class=Database\\Seeders\\PerformanceFixtureSeeder
 * ```
 *
 * Then sign in as `perf@example.test` / `password` and open `/dashboard`,
 * `/deals`, `/work`, and any deal. `php artisan migrate:fresh --seed` does
 * **not** run this — a developer wanting a demo team does not want 2,000
 * activity events, and a two-minute seed on every schema change is a tax on
 * the wrong people.
 *
 * ## Written with the query builder, deliberately
 *
 * Factories are the right tool everywhere else in this repository and the
 * wrong one here: 500 people through `Person::factory()` is 500 round trips
 * plus 500 model boots, and the fixture takes minutes rather than seconds. The
 * rows this writes are the same rows — the shapes are asserted by the suite
 * that uses the models — and what it buys is a fixture somebody will actually
 * wait for.
 */
class PerformanceFixtureSeeder extends Seeder
{
    /** PRD §9's numbers, named rather than typed into loops. */
    private const ACTIVE_DEALS = 25;

    private const PAST_CLIENTS = 500;

    private const ACTIVITY_EVENTS = 2000;

    /** Realistic per-deal volumes, from Emily's description of a live sale. */
    private const STAGES_PER_DEAL = 6;

    private const TASKS_PER_STAGE = 4;

    public function run(): void
    {
        $team = Team::query()->where('slug', 'perf-team')->first();

        if ($team instanceof Team) {
            $this->command->info('Performance fixture already seeded. Drop the team to rebuild it.');

            return;
        }

        $team = Team::query()->create([
            'name' => 'Performance Fixture',
            'slug' => 'perf-team',
            'timezone' => 'America/Denver',
        ]);

        app(TeamContext::class)->runFor($team, function () use ($team): void {
            $agent = $this->agent($team);

            $this->pastClients($team);

            $deals = $this->deals($team, $agent);

            $this->activity($team, $deals, $agent);
        });

        $this->command->info(sprintf(
            'Seeded G8: %d active deals, %d past clients, %d activity events. '
            .'Sign in as perf@example.test / password.',
            self::ACTIVE_DEALS,
            self::PAST_CLIENTS,
            self::ACTIVITY_EVENTS,
        ));
    }

    /**
     * The person the screens are rendered as — a Team Owner, so no permission
     * is the reason a page comes back empty and fast.
     */
    private function agent(Team $team): TeamMembership
    {
        $person = Person::query()->create([
            'email' => 'perf@example.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            /*
             * Enrolled, because PRD §9 makes 2FA mandatory for a Team Owner
             * and an un-enrolled one meets the enrolment screen — which would
             * make every timing here a measurement of a redirect.
             */
            'two_factor_secret' => encrypt('PERFFIXTURESECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['perf-recovery'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Perry',
            'last_name' => 'Fixture',
            'email' => 'perf@example.test',
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', SystemRole::TeamOwner->value)->sole()->getKey(),
        );

        return $membership;
    }

    /**
     * 500 of them, and they are memberships rather than people with logins.
     *
     * PRD F2.1: most people in this product never sign in, and since #140 a
     * name is something a *team* holds. So this is the shape the People
     * directory actually pages through — and `people` rows are created in the
     * same batch because `team_memberships.person_id` is not nullable.
     */
    private function pastClients(Team $team): void
    {
        $now = now();

        foreach (array_chunk(range(1, self::PAST_CLIENTS), 100) as $chunk) {
            $people = [];
            $memberships = [];

            foreach ($chunk as $i) {
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
                    'first_name' => 'Client',
                    'last_name' => 'Number '.$i,
                    'email' => "client{$i}@example.test",
                    'status' => PersonLifecycleState::PastClient->value,
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('people')->insert($people);
            DB::table('team_memberships')->insert($memberships);
        }
    }

    /**
     * 25 active deals, each with a running workflow mid-flight.
     *
     * **Mid-flight matters.** A fixture where every deal sits on stage one
     * measures the cheap case: the dashboard's blocked count, the deals
     * index's current-stage cell and S15's progress strip all walk the stages
     * *behind* the current one, so a fixture with nothing behind it never pays
     * for them.
     *
     * ## Through the real services, and not only for tidiness
     *
     * `workflows.template_snapshot` is `NOT NULL` — the snapshot is the
     * template/instance split, not a nicety — and `SingleMutationPathTest`
     * reads `database/` as well as `app/`, so a seeder writing `stages.state`
     * or `gates.is_met` by hand fails the build. Both facts push the same way,
     * and the result is a fixture whose rows are the ones the product writes
     * rather than ones that merely look like them.
     *
     * @return list<string>
     */
    private function deals(Team $team, TeamMembership $agent): array
    {
        $now = now();
        $ids = [];

        // A seeded system row — every install has the three (PRD §2.2), and
        // `deals.deal_type_id` is not nullable.
        $dealType = DealType::query()->whereNull('team_id')
            ->where('name', 'Seller Representation')->sole();

        $template = $this->template($team);
        $person = $agent->person;
        $instantiate = app(InstantiateWorkflow::class);
        $advance = app(AdvanceWorkflow::class);
        $tasks = app(DealTasks::class);

        for ($i = 0; $i < self::ACTIVE_DEALS; $i++) {
            $deal = Deal::query()->create([
                'deal_type_id' => $dealType->getKey(),
                'name' => "Performance Deal {$i}",
                'state' => DealState::Active,
                'opened_at' => $now->copy()->subDays($i + 10),
            ]);

            $ids[] = (string) $deal->getKey();

            $workflow = $instantiate->handle($deal, $template, $now->copy()->subDays($i + 10));

            /*
             * Walked to the middle. Each hop clears the stage's manual gate
             * and completes its required tasks, because `required_tasks_complete`
             * is on every stage and an override would put a follow-up task on
             * the deal that no real workflow has.
             */
            for ($hop = 0; $hop < intdiv(self::STAGES_PER_DEAL, 2); $hop++) {
                $stage = $workflow->fresh()?->activeStage();

                if (! $stage instanceof Stage) {
                    break;
                }

                foreach ($stage->gates as $gate) {
                    $advance->confirm($workflow->fresh(), $gate, $person);
                }

                foreach ($stage->tasks()->whereNull('completed_at')->get() as $task) {
                    $tasks->complete($deal, $task, $person);
                }

                $advance->handle($workflow->fresh(), $person);
            }
        }

        return $ids;
    }

    /**
     * The team's own template — six stages, each with a manual gate and four
     * required tasks, which is the shape Emily described for a live sale.
     *
     * Not a pack's (#87 is blocked on #11) and not shared: this is a fixture
     * team's own process, and nothing but this seeder ever sees it.
     */
    private function template(Team $team): WorkflowTemplate
    {
        $template = WorkflowTemplate::query()->create([
            'team_id' => $team->getKey(),
            'name' => 'Listing to Close',
            'description' => 'The performance fixture\'s process.',
            'version' => 1,
            'is_active' => true,
        ]);

        for ($order = 0; $order < self::STAGES_PER_DEAL; $order++) {
            $stage = StageTemplate::query()->create([
                'workflow_template_id' => $template->getKey(),
                'name' => "Stage {$order}",
                'sort_order' => $order,
                'expected_duration_days' => 10,
            ]);

            GateTemplate::query()->create([
                'stage_template_id' => $stage->getKey(),
                'gate_type' => 'manual_confirmation',
                'label' => "Requirement on stage {$order}",
                'is_blocking' => true,
                'sort_order' => 0,
            ]);

            for ($t = 0; $t < self::TASKS_PER_STAGE; $t++) {
                TaskTemplate::query()->create([
                    'stage_template_id' => $stage->getKey(),
                    'title' => "Task {$t} on stage {$order}",
                    'is_required' => true,
                    /*
                     * A spread of offsets so My Work's urgency ordering and the
                     * dashboard's fourteen-day tile both have something to sort
                     * — and some genuinely overdue, which is the state those
                     * screens are designed around.
                     */
                    'due_offset_days' => $t - 2,
                    'sort_order' => $t,
                ]);
            }
        }

        return $template;
    }

    /**
     * 2,000 of them, spread across the deals so the rail and the feed page.
     *
     * @param  list<string>  $deals
     */
    private function activity(Team $team, array $deals, TeamMembership $agent): void
    {
        $now = now();
        $types = ['stage.advanced', 'task.completed', 'note.added', 'contact.logged', 'participant.added'];

        foreach (array_chunk(range(1, self::ACTIVITY_EVENTS), 200) as $chunk) {
            $rows = [];

            foreach ($chunk as $i) {
                $dealId = $deals[$i % count($deals)];

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'team_id' => $team->getKey(),
                    'deal_id' => $dealId,
                    'subject_type' => Deal::class,
                    'subject_id' => $dealId,
                    'event_type' => $types[$i % count($types)],
                    'summary' => "Performance event {$i}",
                    'source' => ActivitySource::Manual->value,
                    'actor_person_id' => $agent->person_id,
                    'is_client_visible' => false,
                    'occurred_at' => $now->copy()->subMinutes($i),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('activity_events')->insert($rows);
        }
    }
}
