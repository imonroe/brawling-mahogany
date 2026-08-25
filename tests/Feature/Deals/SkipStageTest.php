<?php

declare(strict_types=1);

use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Stage;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\SkipNeedsAReason;
use App\Support\Workflow\StageNotOnWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F4.12 — Skip and Reopen (#70).
 *
 * IA §7 calls the Override/Skip distinction legally material, so the two live
 * apart all the way down: different permissions, different routes, different
 * audit actions, and different columns. These tests are mostly about that
 * separation holding, and about the two questions #70 asks out loud — what
 * happens to `current_stage_id`, and what a completed workflow does.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    /*
     * `stage.skip` belongs to Team Owner and not to Team Member, which is IA
     * §7's separation showing up in the seeded roles — so the person driving
     * these tests has to be somebody a team gave it to.
     */
    $this->skipper = skipMemberWith([
        Permissions::VIEW_DEALS,
        Permissions::MANAGE_DEALS,
        Permissions::ADVANCE_WORKFLOW,
        Permissions::SKIP_STAGE,
    ], 'skipper');

    $this->actingAsPerson($this->skipper, $this->team);
});

/**
 * A running workflow of `$count` stages, the first active.
 *
 * @return array{0: Workflow, 1: list<Stage>}
 */
function skipWorkflow(Deal $deal, int $count = 3): array
{
    $workflow = Workflow::factory()->create([
        'team_id' => $deal->team_id,
        'deal_id' => $deal->getKey(),
        'name' => 'Listing',
        'state' => WorkflowState::Active,
    ]);

    $stages = [];

    for ($i = 0; $i < $count; $i++) {
        $factory = $i === 0 ? Stage::factory()->active() : Stage::factory();

        $stages[] = $factory->create([
            'team_id' => $deal->team_id,
            'workflow_id' => $workflow->getKey(),
            'name' => 'Stage '.($i + 1),
            'sort_order' => $i,
        ]);
    }

    $workflow->forceFill(['current_stage_id' => $stages[0]->getKey()])->save();

    return [$workflow->fresh(), $stages];
}

/**
 * Somebody on the acting team holding exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function skipMemberWith(array $permissions, string $roleKey): Person
{
    $team = test()->team;
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person, $permissions, $roleKey): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Sam',
            'last_name' => 'Reyes',
            'joined_at' => now(),
        ]);

        $role = Role::query()->create([
            'team_id' => $team->getKey(),
            'key' => $roleKey,
            'name' => Str::headline($roleKey),
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    return $person;
}

function skipUrl(Deal $deal, Workflow $workflow, Stage $stage): string
{
    return "/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/stages/{$stage->getKey()}/skip";
}

function reopenUrl(Deal $deal, Workflow $workflow, Stage $stage): string
{
    return "/deals/{$deal->getKey()}/workflows/{$workflow->getKey()}/stages/{$stage->getKey()}/reopen";
}

it('skips the stage a team is standing on, and moves them to the next', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Cash purchase, so there is no financing contingency here.',
    ])->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Skipped)
        ->and($stages[0]->skipped_reason)->toBe('Cash purchase, so there is no financing contingency here.')
        // Nobody completed it, so nobody is recorded as having.
        ->and($stages[0]->completed_by)->toBeNull()
        ->and($stages[1]->refresh()->state)->toBe(StageState::Active)
        ->and($workflow->refresh()->current_stage_id)->toBe($stages[1]->getKey());
});

it('writes the reason to the audit log and never to the timeline', function (): void {
    /*
     * The same split `override()` makes. The reason is a sentence about
     * somebody's transaction: the audit log has the retention and the access
     * control for it, and the timeline is read by anyone who can see the deal.
     */
    [$workflow, $stages] = skipWorkflow($this->deal);

    $reason = 'Seller is paying cash and waived the appraisal in the contract.';

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), ['reason' => $reason])->assertRedirect();

    $entry = DB::table('audit_log')->where('action', 'workflow.stage_skipped')->sole();

    expect($entry->reason)->toBe($reason)
        ->and($entry->actor_person_id)->toBe($this->skipper->getKey())
        ->and($entry->auditable_id)->toBe($stages[0]->getKey());

    $event = ActivityEvent::query()->where('event_type', 'stage.skipped')->sole();

    expect($event->summary)->toBe('Skipped Stage 1')
        ->and($event->summary)->not->toContain('cash')
        // Subjected to the workflow, deal_id filled, the way an advance is.
        ->and($event->deal_id)->toBe($this->deal->getKey());
});

it('refuses a skip with no reason worth reading', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), ['reason' => 'n/a'])
        ->assertSessionHasErrors('reason');

    expect($stages[0]->refresh()->state)->toBe(StageState::Active);

    // And at the service layer, where a queue job or a native client would
    // arrive. #70's requirement is that it is impossible, not that it is asked.
    expect(fn () => app(AdvanceWorkflow::class)->skip($workflow, $stages[0], $this->skipper, 'n/a'))
        ->toThrow(SkipNeedsAReason::class);
});

it('leaves the pointer alone when the skipped stage is a future one', function (): void {
    // A note about a stage the team has not reached. Nothing moves, because
    // nothing needs to.
    [$workflow, $stages] = skipWorkflow($this->deal);

    $this->post(skipUrl($this->deal, $workflow, $stages[2]), [
        'reason' => 'No HOA on this property, so there are no documents to collect.',
    ])->assertRedirect();

    expect($stages[2]->refresh()->state)->toBe(StageState::Skipped)
        ->and($stages[0]->refresh()->state)->toBe(StageState::Active)
        ->and($workflow->refresh()->current_stage_id)->toBe($stages[0]->getKey());
});

it('lands on the next stage a team could actually work', function (): void {
    /*
     * A cash deal with three financing stages marked inapplicable should not
     * stop on the second and have to skip it again. `stageAfter()` returns the
     * literal next row, which is right for an advance and wrong here.
     */
    [$workflow, $stages] = skipWorkflow($this->deal, 4);

    foreach ([1, 2] as $index) {
        $this->post(skipUrl($this->deal, $workflow, $stages[$index]), [
            'reason' => 'Cash purchase — this financing step does not apply.',
        ])->assertRedirect();
    }

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Cash purchase — this financing step does not apply.',
    ])->assertRedirect();

    expect($workflow->refresh()->current_stage_id)->toBe($stages[3]->getKey())
        ->and($stages[3]->refresh()->state)->toBe(StageState::Active);
});

it('completes the workflow when the last workable stage is skipped', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal, 1);

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Not applicable to a rental placement.',
    ])->assertRedirect();

    expect($workflow->refresh()->state)->toBe(WorkflowState::Completed)
        ->and($workflow->current_stage_id)->toBeNull()
        ->and(ActivityEvent::query()->where('event_type', 'workflow.completed')->count())->toBe(1);
});

it('refuses to skip a stage that was already worked', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    $stages[0]->forceFill(['state' => StageState::Complete])->save();

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Actually this did not apply after all.',
    ])->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Complete)
        ->and(DB::table('audit_log')->where('action', 'workflow.stage_skipped')->count())->toBe(0);
});

it('reopens the last completed stage and puts the current one back in the queue', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    app(AdvanceWorkflow::class)->handle($workflow, $this->skipper);

    expect($workflow->refresh()->current_stage_id)->toBe($stages[1]->getKey());

    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Active)
        ->and($stages[0]->actual_end)->toBeNull()
        ->and($stages[0]->completed_by)->toBeNull()
        ->and($stages[1]->refresh()->state)->toBe(StageState::Pending)
        ->and($stages[1]->actual_start)->toBeNull()
        ->and($workflow->refresh()->current_stage_id)->toBe($stages[0]->getKey())
        ->and(DB::table('audit_log')->where('action', 'workflow.stage_reopened')->count())->toBe(1)
        ->and(ActivityEvent::query()->where('event_type', 'stage.reopened')->count())->toBe(1);
});

it('reopens a skipped stage, clearing the reason with it', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Thought this was a cash deal; the buyer is financing after all.',
    ])->assertRedirect();

    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Active)
        ->and($stages[0]->skipped_reason)->toBeNull()
        ->and($workflow->refresh()->current_stage_id)->toBe($stages[0]->getKey());
});

it('reopens only the most recently finished stage', function (): void {
    /*
     * Reopening stage 1 of 3 while stage 3 is active has no defensible
     * meaning: either two completed stages silently un-complete, or the
     * workflow holds two active stages. Repeating the reopen walks backwards
     * one stage at a time, which is the same thing said slowly.
     */
    [$workflow, $stages] = skipWorkflow($this->deal);

    app(AdvanceWorkflow::class)->handle($workflow, $this->skipper);
    app(AdvanceWorkflow::class)->handle($workflow->fresh(), $this->skipper);

    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Complete)
        ->and(DB::table('audit_log')->where('action', 'workflow.stage_reopened')->count())->toBe(0);

    // The one immediately behind the pointer is fair game, and once it is
    // reopened the one behind *that* becomes so.
    $this->post(reopenUrl($this->deal, $workflow, $stages[1]))->assertRedirect();
    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Active)
        ->and($stages[1]->refresh()->state)->toBe(StageState::Pending);
});

it('refuses to reopen inside a finished workflow', function (): void {
    /*
     * `Workflow::stateTransitions()` decided this before #70 existed: *"reopen
     * the inspection stage" is a real request, "un-complete the entire sale"
     * is not.* A stage made active inside a workflow `handle()` will not
     * advance is a dead end, which is the shape of defect where each half
     * works and the pair does not.
     */
    [$workflow, $stages] = skipWorkflow($this->deal, 1);

    app(AdvanceWorkflow::class)->handle($workflow, $this->skipper);

    expect($workflow->refresh()->state)->toBe(WorkflowState::Completed);

    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Complete)
        ->and(DB::table('audit_log')->where('action', 'workflow.stage_reopened')->count())->toBe(0);
});

it('refuses to reopen a stage that was never finished', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    $this->post(reopenUrl($this->deal, $workflow, $stages[1]))->assertRedirect();

    expect($stages[1]->refresh()->state)->toBe(StageState::Pending)
        ->and(DB::table('audit_log')->where('action', 'workflow.stage_reopened')->count())->toBe(0);
});

it('keeps skip behind its own permission', function (): void {
    /*
     * IA §7 keeps the three verbs apart precisely so a team can hand out one
     * without the others. An assistant advances stages all day and must not
     * thereby be able to decide the appraisal was not part of this sale.
     */
    [$workflow, $stages] = skipWorkflow($this->deal);

    $advancer = skipMemberWith([
        Permissions::VIEW_DEALS,
        Permissions::ADVANCE_WORKFLOW,
    ], 'advancer');

    $this->actingAsPerson($advancer, $this->team);

    $this->post(skipUrl($this->deal, $workflow, $stages[0]), [
        'reason' => 'Cash purchase, no financing contingency on this one.',
    ])->assertForbidden();

    expect($stages[0]->refresh()->state)->toBe(StageState::Active);
});

it('lets the same person reopen, because reopening is undoing an advance', function (): void {
    [$workflow, $stages] = skipWorkflow($this->deal);

    app(AdvanceWorkflow::class)->handle($workflow, $this->skipper);

    $advancer = skipMemberWith([
        Permissions::VIEW_DEALS,
        Permissions::ADVANCE_WORKFLOW,
    ], 'advancer-two');

    $this->actingAsPerson($advancer, $this->team);

    $this->post(reopenUrl($this->deal, $workflow, $stages[0]))->assertRedirect();

    expect($stages[0]->refresh()->state)->toBe(StageState::Active);
});

it('refuses a stage that belongs to another workflow on the same deal', function (): void {
    /*
     * F4.7 lets one deal run several workflows at once, so two stages in the
     * same team, on the same deal, with no `team_id` and no policy between
     * them is the ordinary case. Only the nesting answers "whose workflow".
     */
    [$workflow] = skipWorkflow($this->deal);
    [$other, $otherStages] = skipWorkflow($this->deal);

    expect(fn () => app(AdvanceWorkflow::class)->skip(
        $workflow,
        $otherStages[0],
        $this->skipper,
        'This stage is not on that workflow at all.',
    ))->toThrow(StageNotOnWorkflow::class);

    unset($other);
});
