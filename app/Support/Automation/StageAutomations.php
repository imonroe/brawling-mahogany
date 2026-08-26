<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\Stage;

/**
 * The automations a running stage carries, read out of its workflow's
 * snapshot (issue #92).
 *
 * **Never `action_definitions`.** F4.5 is the rule and PRD §7.1 calls it the
 * highest-impact correction in the document: a template edit must not rewrite
 * a deal already running. An automation is as much part of a process as a gate
 * is, so it is snapshotted with the rest of it, and this is what reads it back.
 *
 * ## Matched by `sort_order`, and that is the only stable key there is
 *
 * `stages` carries no pointer to the `stage_template` it was copied from, by
 * design — the runtime layer does not reach into the definition layer. What it
 * does copy verbatim is `sort_order`, which is the template's own ordering and
 * unique within a workflow. Names are not: two stages in one process may
 * legitimately share one.
 */
final class StageAutomations
{
    /**
     * Everything on this stage that answers to the given trigger.
     *
     * @return list<SnapshotAutomation>
     */
    public function on(Stage $stage, AutomationTrigger $trigger): array
    {
        return array_values(array_filter(
            $this->all($stage),
            fn (SnapshotAutomation $automation): bool => $automation->trigger === $trigger,
        ));
    }

    /**
     * @return list<SnapshotAutomation>
     */
    public function all(Stage $stage): array
    {
        $stage->loadMissing('workflow');

        $snapshot = $stage->workflow->template_snapshot ?? [];
        $stages = is_array($snapshot['stages'] ?? null) ? $snapshot['stages'] : [];

        foreach ($stages as $entry) {
            if (! is_array($entry) || ($entry['sort_order'] ?? null) !== $stage->sort_order) {
                continue;
            }

            return $this->read(is_array($entry['automations'] ?? null) ? $entry['automations'] : []);
        }

        /*
         * No entry is an ordinary answer, not a broken one: every workflow
         * instantiated before this slice has a snapshot with no `automations`
         * key at all, and a stage added to a running workflow by hand has no
         * snapshot entry either. Both mean the same thing — nothing fires.
         */
        return [];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<SnapshotAutomation>
     */
    private function read(array $entries): array
    {
        $automations = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $trigger = AutomationTrigger::tryFrom((string) ($entry['trigger'] ?? ''));
            $action = AutomationActionType::tryFrom((string) ($entry['action_type'] ?? ''));

            /*
             * A snapshot written by a later version may carry a trigger or an
             * action this build does not know. Skipped rather than guessed at:
             * the alternative is firing *something* for an instruction we
             * cannot read, and the whole point of this feature is that nothing
             * unintended reaches a client.
             */
            if ($trigger === null || $action === null) {
                continue;
            }

            $automations[] = new SnapshotAutomation(
                actionDefinitionId: is_string($entry['action_definition_id'] ?? null)
                    ? $entry['action_definition_id']
                    : null,
                trigger: $trigger,
                actionType: $action,
                messageTemplateId: is_string($entry['message_template_id'] ?? null)
                    ? $entry['message_template_id']
                    : null,
                config: is_array($entry['config'] ?? null) ? $entry['config'] : [],
                gateSortOrder: is_int($entry['gate_sort_order'] ?? null)
                    ? $entry['gate_sort_order']
                    : null,
                requiresApproval: (bool) ($entry['requires_approval'] ?? false),
                isManual: (bool) ($entry['is_manual'] ?? false),
            );
        }

        return $automations;
    }
}
