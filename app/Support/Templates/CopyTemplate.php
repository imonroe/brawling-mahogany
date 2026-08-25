<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\GateTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Take a copy of a template a team may read but not edit (S39, S41 · #84, #85).
 *
 * `WorkflowTemplatePolicy::update()` refuses a system template outright, which
 * is right — a pack is shared by every team and one team's edit must not reach
 * the others. So the way a team customises Emily's listing pack is to **take a
 * copy of it**, and this is that act.
 *
 * ## A copy is deep, and for the same reason instantiation is
 *
 * PRD §7.1's template/instance split exists because *"editing a template must
 * never change a deal already running"*. A shallow copy — a new workflow
 * template pointing at the pack's stage rows — would reintroduce exactly that
 * coupling one level up: the team edits their stage, and every other team's
 * pack changes. So the stages, their gates and their tasks are all copied.
 *
 * ## The copy is not the pack's any more
 *
 * `template_pack_id` is dropped deliberately. A row that still names the pack
 * is a row a future "update your packs" feature would try to reconcile, and
 * the whole point of taking a copy is that it is now the team's to diverge.
 * What is kept is a `description` that says where it came from, because six
 * months later *"why do we have two listing workflows"* is a real question.
 */
final class CopyTemplate
{
    public function into(WorkflowTemplate $template, Team $team, ?string $name = null): WorkflowTemplate
    {
        return DB::transaction(function () use ($template, $team, $name): WorkflowTemplate {
            $template->loadMissing(['stageTemplates.gateTemplates', 'stageTemplates.taskTemplates']);

            $copy = new WorkflowTemplate;

            $copy->forceFill([
                'team_id' => $team->getKey(),
                // Not the pack's any more — see the docblock.
                'template_pack_id' => null,
                'name' => $name ?? $template->name.' (copy)',
                'description' => $template->description,
                'version' => 1,
                'is_active' => true,
            ])->save();

            foreach ($template->stageTemplates as $stage) {
                $stageCopy = new StageTemplate;

                $stageCopy->forceFill([
                    'workflow_template_id' => $copy->getKey(),
                    ...$stage->only([
                        'name', 'description', 'sort_order', 'expected_duration_days',
                        'owner_role', 'is_milestone', 'client_facing_label',
                    ]),
                ])->save();

                foreach ($stage->gateTemplates as $gate) {
                    (new GateTemplate)->forceFill([
                        'stage_template_id' => $stageCopy->getKey(),
                        ...$gate->only(['gate_type', 'label', 'config', 'is_blocking', 'sort_order']),
                    ])->save();
                }

                foreach ($stage->taskTemplates as $task) {
                    (new TaskTemplate)->forceFill([
                        'stage_template_id' => $stageCopy->getKey(),
                        ...$task->only([
                            'title', 'description', 'owner_role',
                            'due_offset_days', 'is_required', 'sort_order',
                        ]),
                    ])->save();
                }
            }

            return $copy->fresh() ?? $copy;
        });
    }
}
