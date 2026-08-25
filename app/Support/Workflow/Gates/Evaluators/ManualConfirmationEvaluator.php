<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * Somebody ticked it (PRD §4.4 · issue #67).
 *
 * The only gate type whose truth lives on the gate row itself — every other
 * type derives its answer from something else, which is why they ignore
 * `is_met` and this one is defined by it.
 */
final class ManualConfirmationEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'manual_confirmation';
    }

    public static function label(): string
    {
        return 'Manual confirmation';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        if ($gate->is_met) {
            return GateVerdict::met('Confirmed.');
        }

        return GateVerdict::unmet(
            'Nobody has confirmed this yet.',
            ['type' => 'gate', 'gate' => $gate->getKey()],
        );
    }
}
