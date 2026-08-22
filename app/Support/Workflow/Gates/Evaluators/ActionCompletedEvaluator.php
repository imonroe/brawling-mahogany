<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * A specific action instance fired successfully — **Slice 3** (issue #92).
 *
 * See `DocumentPresentEvaluator` for why a deferred type is a real class
 * rather than a gap in the registry.
 */
final class ActionCompletedEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'action_completed';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        return GateVerdict::notYetWired(
            'This stage is waiting on an automation to run, and automations arrive in Slice 3.',
            '#92',
        );
    }
}
