<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Models\TeamMembership;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * A specified role recorded approval (issue #67).
 *
 * Config: `{"role": "team_owner"}`.
 *
 * ## Why this is not just `manual_confirmation` with a label
 *
 * It records *who may tick it*, and the difference matters on the one screen
 * where it is used: a price reduction the owner has to sign off is not the
 * same as a checkbox the assistant clears on their way past. The approval is
 * stored the same way — `is_met`, `met_at`, `met_by` — and this evaluator
 * checks the approver still holds the role.
 *
 * Checking *now* rather than trusting the moment of approval is deliberate. An
 * approval from somebody who has since left the team is not an approval, and a
 * gate that cleared on it would be reporting a fact that stopped being true.
 */
final class ApprovalEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'approval';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $role = (string) ($gate->configuration()['role'] ?? '');

        if ($role === '') {
            return GateVerdict::unmet(
                'This gate does not say whose approval it needs, so it cannot be checked.',
                ['type' => 'gate_config', 'gate' => $gate->getKey()],
            );
        }

        if (! $gate->is_met || $gate->met_by === null) {
            return GateVerdict::unmet(
                'This still needs approval.',
                ['type' => 'gate', 'gate' => $gate->getKey(), 'role' => $role],
            );
        }

        $approverStillHoldsRole = TeamMembership::query()
            ->where('person_id', $gate->met_by)
            ->whereNull('revoked_at')
            ->whereHas('roles', fn ($query) => $query->where('roles.key', $role))
            ->exists();

        if (! $approverStillHoldsRole) {
            return GateVerdict::unmet(
                'This was approved by somebody who no longer holds that role, so it needs approving again.',
                ['type' => 'gate', 'gate' => $gate->getKey(), 'role' => $role],
            );
        }

        return GateVerdict::met('Approved.');
    }
}
