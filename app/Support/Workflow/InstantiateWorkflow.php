<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Enums\StageState;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\GateTemplate;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\ForeignReferenceException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turn a template into a running workflow, once (PRD F4.5 · issue #66).
 *
 * `CLAUDE.md`, restating F4.5:
 *
 * > **Instantiating a template snapshots it — later template edits must never
 * > rewrite an in-flight deal.**
 *
 * A team that reorders its listing workflow in September must not change what
 * happened on a deal that closed in August, and must not reorder the stages of
 * a deal sitting at stage four right now.
 *
 * ## What snapshot means precisely
 *
 * Two independent things, and both are needed:
 *
 * 1. **The tree is copied.** Stages, gates, and tasks become real rows in the
 *    runtime tables. Nothing at advance time joins back to a template.
 * 2. **The definition is written to `workflows.template_snapshot`.** So the
 *    original is recoverable even if every template row is later deleted, and
 *    so "what did this deal's process actually say in August" has an answer.
 *
 * The copy is what makes it *work*; the JSON is what makes it *auditable*.
 * `workflow_template_id` survives for reporting and S41's in-use warning, and
 * is never read to decide behaviour.
 *
 * ## Instantiating twice is allowed and means two workflows
 *
 * F4.7, and PRD §7.5's correction: one deal, many workflow instances.
 * Pre-listing improvements and the sale itself run concurrently.
 */
final class InstantiateWorkflow
{
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * @param  array<string, string>  $roleAssignments  owner_role => person id
     */
    public function handle(
        Deal $deal,
        WorkflowTemplate $template,
        ?CarbonInterface $startingOn = null,
        array $roleAssignments = [],
    ): Workflow {
        /*
         * The template has to be one this deal's team may actually use.
         *
         * `workflow_templates` is a shared table — a null `team_id` is a
         * system template from a pack — so no foreign key can express "mine or
         * everybody's", and nothing was checking. Slice 2's first review
         * instantiated another team's private template and watched its stage
         * names, which are a team's process written down, land in the other
         * team's runtime rows.
         *
         * Checked here rather than in the controller that will call it,
         * because that controller is #74 and does not exist yet.
         */
        if (! $template->isSystem() && $template->team_id !== $deal->team_id) {
            throw ForeignReferenceException::for('workflow_templates', $template->getKey(), $deal->team_id);
        }

        /*
         * And the people the roles resolve to have to be on this team.
         *
         * `tasks.assignee_id` is a plain `people` reference — it has to be,
         * because `people` is the login table and carries no `team_id` — so
         * the database cannot refuse an id from another team. The only caller
         * today is a test; the caller that matters is #74, which will pass a
         * request body straight through. Validating here means that controller
         * inherits the check rather than having to remember it.
         */
        $roleAssignments = $this->assignableWithin($deal, $roleAssignments);

        $start = $startingOn instanceof CarbonInterface ? $startingOn->toImmutable() : Carbon::now()->toImmutable();

        // Loaded once, outside the transaction, and used for both the copy and
        // the snapshot — so the two cannot describe different things.
        $template->load('stageTemplates.gateTemplates', 'stageTemplates.taskTemplates');

        $workflow = DB::transaction(function () use ($deal, $template, $start, $roleAssignments): Workflow {
            $workflow = new Workflow;
            $workflow->forceFill([
                'team_id' => $deal->team_id,
                'deal_id' => $deal->getKey(),
                'workflow_template_id' => $template->getKey(),
                'template_snapshot' => $this->snapshot($template),
                'name' => $template->name,
                'state' => WorkflowState::NotStarted->value,
                'planned_start' => $start->toDateString(),
            ])->save();

            $cursor = $start;
            $stages = [];

            foreach ($template->stageTemplates as $stageTemplate) {
                [$stage, $cursor] = $this->copyStage($workflow, $stageTemplate, $cursor, $roleAssignments, $deal);
                $stages[] = $stage;
            }

            $workflow->forceFill([
                'planned_end' => $cursor->toDateString(),
            ])->save();

            /*
             * Activating the first stage is part of instantiation rather than a
             * first advance, and the distinction is not cosmetic: an advance
             * evaluates the gates on the stage being *left*, and there is no
             * such stage here. Routing this through `AdvanceWorkflow` would
             * mean inventing a stage-zero for it to complete.
             */
            $first = $stages[0] ?? null;

            if ($first instanceof Stage) {
                $first->forceFill([
                    'state' => StageState::Active->value,
                    'actual_start' => now(),
                ])->save();

                $workflow->forceFill([
                    'state' => WorkflowState::Active->value,
                    'current_stage_id' => $first->getKey(),
                    'actual_start' => now(),
                ])->save();
            }

            return $workflow;
        });

        $this->activity->record(
            subject: $workflow,
            eventType: 'workflow.started',
            summary: "Started {$workflow->name}",
            deal: $deal,
        );

        return $workflow;
    }

    /**
     * @param  array<string, string>  $roleAssignments
     * @return array{0: Stage, 1: CarbonInterface}
     */
    private function copyStage(
        Workflow $workflow,
        StageTemplate $stageTemplate,
        CarbonInterface $cursor,
        array $roleAssignments,
        Deal $deal,
    ): array {
        $duration = $stageTemplate->expected_duration_days;
        $plannedEnd = $duration === null ? null : $cursor->addDays($duration);

        $stage = new Stage;
        $stage->forceFill([
            'team_id' => $workflow->team_id,
            'workflow_id' => $workflow->getKey(),
            'name' => $stageTemplate->name,
            'description' => $stageTemplate->description,
            'sort_order' => $stageTemplate->sort_order,
            'state' => StageState::Pending->value,
            'planned_start' => $cursor->toDateString(),
            'planned_end' => ($plannedEnd ?? $cursor)->toDateString(),
            'is_milestone' => $stageTemplate->is_milestone,
            'milestone_label' => $stageTemplate->client_facing_label,
        ])->save();

        foreach ($stageTemplate->gateTemplates as $gateTemplate) {
            $gate = new Gate;
            $gate->forceFill([
                'team_id' => $workflow->team_id,
                'stage_id' => $stage->getKey(),
                'gate_type' => $gateTemplate->gate_type,
                'label' => $gateTemplate->label,
                'config' => $gateTemplate->config,
                'is_blocking' => $gateTemplate->is_blocking,
                'sort_order' => $gateTemplate->sort_order,
            ])->save();
        }

        foreach ($stageTemplate->taskTemplates as $taskTemplate) {
            $offset = $taskTemplate->due_offset_days;

            $task = new Task;
            $task->forceFill([
                'team_id' => $workflow->team_id,
                'deal_id' => $deal->getKey(),
                'stage_id' => $stage->getKey(),
                'title' => $taskTemplate->title,
                'description' => $taskTemplate->description,
                // Signed: a negative offset is "before the stage opens", which
                // is how "order the survey three days early" is expressed.
                'due_date' => $offset === null ? null : $cursor->addDays($offset)->toDateString(),
                'assignee_id' => $this->resolveOwner($taskTemplate->owner_role, $roleAssignments),
                'is_required' => $taskTemplate->is_required,
                'source' => TaskSource::Template->value,
                'sort_order' => $taskTemplate->sort_order,
            ])->save();
        }

        return [$stage, $plannedEnd ?? $cursor];
    }

    /**
     * A role becomes a person, here and nowhere else.
     *
     * The template said "transaction coordinator" because naming Heather would
     * break the day a team has a different assistant. This is the one moment
     * that abstraction is cashed in, and an unassigned role is a null assignee
     * rather than a guess — an unassigned task is visible on the stage and
     * fixable in a click, while a task silently assigned to the wrong person
     * is a task nobody does.
     *
     * @param  array<string, string>  $roleAssignments
     */
    private function resolveOwner(?string $ownerRole, array $roleAssignments): ?string
    {
        if ($ownerRole === null) {
            return null;
        }

        return $roleAssignments[$ownerRole] ?? null;
    }

    /**
     * Keep only the assignments naming somebody this team actually has.
     *
     * Dropped rather than thrown, and the difference is what each mistake
     * means. A foreign *template* is somebody reading another team's process,
     * which has no innocent reading. A stale person id is the ordinary result
     * of a colleague leaving between the picker rendering and the form being
     * submitted — and the existing answer to "no person for this role" is
     * already a null assignee that shows up unassigned on the stage and is
     * fixable in a click.
     *
     * @param  array<string, string>  $roleAssignments
     * @return array<string, string>
     */
    private function assignableWithin(Deal $deal, array $roleAssignments): array
    {
        if ($roleAssignments === []) {
            return [];
        }

        $onTheTeam = TeamMembership::withoutTeamScope()
            ->where('team_id', $deal->team_id)
            ->whereNull('revoked_at')
            ->whereIn('person_id', array_values($roleAssignments))
            ->pluck('person_id')
            ->all();

        return array_filter(
            $roleAssignments,
            fn (string $personId): bool => in_array($personId, $onTheTeam, true),
        );
    }

    /**
     * The definition, frozen (F4.5).
     *
     * Deliberately a plain array built by hand rather than `toArray()` on the
     * models: this is a record meant to be readable in five years, so it holds
     * the fields the process is made of and not whatever columns the tables
     * happened to have. A migration that renames a column must not change what
     * an old snapshot says happened.
     *
     * @return array<string, mixed>
     */
    private function snapshot(WorkflowTemplate $template): array
    {
        return [
            'captured_at' => Carbon::now()->toIso8601String(),
            'workflow_template' => [
                'id' => $template->getKey(),
                'name' => $template->name,
                'description' => $template->description,
                'version' => $template->version,
            ],
            'stages' => $template->stageTemplates->map(fn (StageTemplate $stage): array => [
                'name' => $stage->name,
                'description' => $stage->description,
                'sort_order' => $stage->sort_order,
                'expected_duration_days' => $stage->expected_duration_days,
                'owner_role' => $stage->owner_role,
                'is_milestone' => $stage->is_milestone,
                'client_facing_label' => $stage->client_facing_label,
                'gates' => $stage->gateTemplates->map(fn (GateTemplate $gate): array => [
                    'gate_type' => $gate->gate_type,
                    'label' => $gate->label,
                    'config' => $gate->config,
                    'is_blocking' => $gate->is_blocking,
                    'sort_order' => $gate->sort_order,
                ])->values()->all(),
                'tasks' => $stage->taskTemplates->map(fn (TaskTemplate $task): array => [
                    'title' => $task->title,
                    'description' => $task->description,
                    'owner_role' => $task->owner_role,
                    'due_offset_days' => $task->due_offset_days,
                    'is_required' => $task->is_required,
                    'sort_order' => $task->sort_order,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
