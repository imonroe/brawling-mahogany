<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Models\Gate;
use App\Models\Stage;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * A specific automation on this stage has run (PRD §4.4 · issue #92).
 *
 * Config: `{"actionDefinitionId": "...", "label": "Welcome email"}`.
 *
 * The last of Slice 2's three deferred evaluators to be wired, and the one
 * whose deferral was cheapest — until #92 there were no `action_instances` at
 * all, so the honest answer was `notYetWired()`.
 *
 * ## The configuration is required, and an unconfigured gate refuses
 *
 * Same rule, and the same reason, as `FieldPopulatedEvaluator`: a gate that
 * cannot say what it is waiting for is a **configuration error that refuses**,
 * not a silent pass. The tempting alternative — *"no automation named, so
 * check that nothing on this stage is still queued"* — reads as a reasonable
 * default and behaves as a hole: a stage that raised no automations at all
 * would satisfy it trivially, and a gate that always clears is a gate the
 * template author believes is protecting them.
 *
 * `GateRegistry::selectableOptions()` still withholds this type, for the
 * reason it withholds the other four that need configuration: S43 has no
 * editor for `actionDefinitionId` yet, and a picker offering a type somebody
 * cannot finish configuring hands them a stage only an **override** can pass.
 * What changes here is that a gate carrying the configuration — from a pack,
 * or from a template built when that editor lands — now has an answer.
 *
 * ## Sent, and not merely raised
 *
 * `awaiting_approval` is the state F5.7 exists to create, and a gate that
 * cleared on a message sitting in the approval queue would let a deal advance
 * past a client communication nobody has read yet. `failed` does not clear it
 * either: the point of the gate is that the thing happened.
 */
final class ActionCompletedEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'action_completed';
    }

    public static function label(): string
    {
        return 'Action completed';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $definitionId = $gate->configuration()['actionDefinitionId'] ?? null;

        if (! is_string($definitionId) || $definitionId === '') {
            return GateVerdict::unmet(
                'This gate does not say which automation it is waiting for, so it cannot be checked.',
                ['type' => 'gate_config', 'gate' => $gate->getKey()],
            );
        }

        $stage = $gate->stage;

        if (! $stage instanceof Stage) {
            return GateVerdict::unmet(
                'This gate is attached to a stage that no longer exists, so it cannot be checked.',
                ['type' => 'gate_config', 'gate' => $gate->getKey()],
            );
        }

        $label = (string) ($gate->configuration()['label'] ?? 'The automation this stage waits on');

        /*
         * Scoped by the stage as well as the definition, deliberately.
         *
         * One automation on a stage template produces one instance per
         * *running stage*, and a deal can run the same workflow template
         * twice — F4.7 lets one deal carry several workflows at once. Asking
         * only about the definition would let an instance sent on a different
         * stage clear this one.
         */
        $instances = ActionInstance::query()
            ->where('stage_id', $stage->getKey())
            ->where('action_definition_id', $definitionId)
            ->get();

        if ($instances->isEmpty()) {
            return GateVerdict::unmet(
                "{$label} has not run yet.",
                ['type' => 'automation', 'stage' => $stage->getKey()],
            );
        }

        if ($instances->contains(fn (ActionInstance $instance): bool => $instance->state === AutomationState::Sent)) {
            return GateVerdict::met("{$label} has run.");
        }

        $waiting = $instances->first(
            fn (ActionInstance $instance): bool => $instance->state === AutomationState::AwaitingApproval,
        );

        if ($waiting instanceof ActionInstance) {
            return GateVerdict::unmet(
                "{$label} is waiting for somebody to review it before it goes out.",
                ['type' => 'message_approval', 'message' => $waiting->getKey()],
            );
        }

        $failed = $instances->first(
            fn (ActionInstance $instance): bool => $instance->state === AutomationState::Failed,
        );

        if ($failed instanceof ActionInstance) {
            return GateVerdict::unmet(
                "{$label} did not go out: ".($failed->error ?? 'no reason was recorded.'),
                ['type' => 'message', 'message' => $failed->getKey()],
            );
        }

        return GateVerdict::unmet(
            "{$label} has not run yet.",
            ['type' => 'automation', 'stage' => $stage->getKey()],
        );
    }
}
