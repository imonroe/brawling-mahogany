<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S47's query budget (issue #93).
 *
 * The house standard, from `PeopleIndexBudgetTest`: *"the same page, ten times
 * the rows, the same number of queries."*
 *
 * This screen earns a budget for a reason `CLAUDE.md` records in full. S13
 * shipped an eager-load nothing rendered, selecting a column that did not
 * exist, and answered 500 for any team whose deals had a property — because
 * *"a relation nothing renders is a relation nothing thinks to seed"*. This
 * screen eager-loads **two** relations per row, and both of them are the kind
 * a fixture forgets: the deal a message is about, and the template it came
 * from. So every row here has a deal and a template, or the guard measures
 * nothing.
 *
 * It is also the screen most likely to grow another list. It already carries
 * four — waiting, held, failing, recent — plus three counts, and each is a
 * fixed cost that a per-row budget cannot see. `toBe($small)` proves the
 * absence of an N+1 and nothing else; the **absolute** assertion beside it is
 * what noticed two queries being added, which the growth check waved through
 * while this paragraph claimed it would not.
 */
function seedQueue(int $count): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count): void {
        /*
         * A **template per message**, not one shared. A single template would
         * be one query however the controller asked for it, which is the
         * shape S13's finding warns about: a fixture that cannot grow the
         * relation cannot catch an N+1 on it.
         */
        for ($i = 0; $i < $count; $i++) {
            $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

            $template = MessageTemplate::factory()->create([
                'team_id' => $team->getKey(),
                'name' => "Template {$i}",
            ]);

            foreach ([
                AutomationState::AwaitingApproval,
                AutomationState::Failed,
                AutomationState::Sent,
            ] as $state) {
                ActionInstance::factory()->create([
                    'team_id' => $team->getKey(),
                    'deal_id' => $deal->getKey(),
                    'message_template_id' => $template->getKey(),
                    'state' => $state,
                    'executed_at' => $state === AutomationState::AwaitingApproval ? null : now(),
                ]);
            }

            /*
             * **And a held one, per iteration.** `held` was the one list with
             * no fixture in the growth cases, so its eager loads never
             * executed — removing them left every assertion in this file
             * green, which is `CLAUDE.md`'s S13 shape exactly. It also meant
             * the absolute count below described the screen only while the
             * feature was idle.
             */
            ActionInstance::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'message_template_id' => $template->getKey(),
                'state' => AutomationState::Pending,
                'error' => 'This team has reached its limit of messages for the hour.',
            ]);
        }
    });

    return [$team, $member];
}

function countQueueQueries(callable $request): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $request();

    return $queries;
}

it('does not grow its query count with the queue', function (): void {
    [$smallTeam, $smallMember] = seedQueue(2);

    $this->actingAsPerson($smallMember, $smallTeam);

    $small = countQueueQueries(fn () => $this->get('/messages')->assertOk());

    [$largeTeam, $largeMember] = seedQueue(30);

    $this->actingAsPerson($largeMember, $largeTeam);

    $large = countQueueQueries(fn () => $this->get('/messages')->assertOk());

    expect($large)->toBe($small);

    /*
     * **And the absolute number**, which the growth assertion above cannot
     * see. `toBe($small)` is a per-row budget and is blind to fixed cost by
     * construction — two queries were added to this screen and it stayed green
     * throughout, while this file's own docblock claimed the opposite.
     *
     * So the count is pinned, and it is measured with **every** list
     * populated — the first version of this number was taken with the held
     * list empty, so it described the screen only while the feature was idle
     * and was two short of the real cost. It will need editing when a list is
     * added, and that is the point: another query on the screen a team opens
     * all day should have to be argued for in a diff rather than absorbed.
     */
    /*
     * **34, down from 35**, while gaining a third badge (#101).
     *
     * `PeopleIndexBudgetTest` predicted this shape: *"two is where the shell's
     * counts stop being free, and a third would need one query returning
     * several counts."* `ShellCounts` is that query, so the shell now issues
     * one round trip where it issued two — the notification count rides along
     * for nothing and the message count comes back with it.
     *
     * Pinned to an exact number rather than a ceiling for the reason above:
     * another query on the screen a team opens all day should have to be
     * argued for in a diff.
     */
    expect($large)->toBe(34);
});

it('really did render the larger queue', function (): void {
    /*
     * The other half, and the one that stops *"the same number of queries"*
     * being equally true of two fixtures that were never built. Same trap the
     * deal-overview budget records: an assertion about a count is an
     * assertion about nothing if the rows are not there.
     */
    [$team, $member] = seedQueue(30);

    $this->actingAsPerson($member, $team);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('waiting', 30)
        ->has('failing', 30)
        ->where('totals.waiting', 30)
        // And the names really were resolved through the relation, which is
        // what the eager-load is for.
        ->where('waiting.0.templateName', 'Template 0'));
});

it('seeds every list the screen renders', function (): void {
    /*
     * `held` was the one list with no fixture anywhere in this file, so its
     * eager loads were never executed by the guard — which is exactly
     * `CLAUDE.md`'s S13 shape: *"a relation nothing renders is a relation
     * nothing thinks to seed"*, and that one shipped a 500.
     */
    [$team, $member] = seedQueue(2);

    app(TeamContext::class)->runFor($team, function () use ($team): void {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        $template = MessageTemplate::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Held template',
        ]);

        // One held by a rail, one handed to a transport and unconfirmed.
        ActionInstance::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'message_template_id' => $template->getKey(),
            'error' => 'This team has reached its limit of messages for the hour.',
        ]);

        ActionInstance::factory()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'message_template_id' => $template->getKey(),
            'message_key' => 'claimed-and-never-confirmed',
        ]);
    });

    $this->actingAsPerson($member, $team);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        // Two seeded here, plus the one `seedQueue()` now creates per deal.
        ->has('held', 4)
        ->where('totals.held', 4)
        /*
         * Resolved through the eager load rather than the payload fallback —
         * the factory's payload carries "Inspection scheduled", so any
         * template name proves the relation was read. Oldest first, so this is
         * `seedQueue()`'s own held row rather than the two added above.
         */
        ->where('held.0.templateName', 'Template 0'));
});

it('bounds both lists rather than shipping every held message', function (): void {
    /*
     * A team inside F5.7's first-month window has *every* outbound message on
     * this screen, so the lists are capped — and the page says so, because a
     * queue that silently shows 200 of 340 is a queue somebody believes they
     * have cleared.
     */
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team): void {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        ActionInstance::factory()->count(210)->awaitingApproval()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
        ]);
    });

    $this->actingAsPerson($member, $team);

    $this->get('/messages')->assertInertia(fn ($page) => $page
        ->has('waiting', 200)
        ->where('totals.waiting', 210));

    unset($member);
});

it('keeps the shell’s pending count to one query', function (): void {
    /*
     * The badge is on every screen in the product, so its cost is paid on
     * every request — `PeopleIndexBudgetTest` names it as the second of the
     * shell's two counts and says two is where they stop being free.
     */
    [$team, $member] = seedQueue(20);

    $this->actingAsPerson($member, $team);

    $withBadge = countQueueQueries(fn () => $this->get('/dashboard')->assertOk());

    [$biggerTeam, $biggerMember] = seedQueue(60);

    $this->actingAsPerson($biggerMember, $biggerTeam);

    expect(countQueueQueries(fn () => $this->get('/dashboard')->assertOk()))->toBe($withBadge);

    unset($team, $member);
});
