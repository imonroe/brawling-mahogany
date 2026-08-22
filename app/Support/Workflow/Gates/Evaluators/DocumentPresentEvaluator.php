<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * A document of a required category is attached — **Slice 3** (issue #104).
 *
 * Built now against the interface so the registry is complete and a template
 * can carry the gate type, and so nothing has to be added to `AdvanceWorkflow`
 * when the documents module lands. Until then it returns an explanatory unmet
 * naming the issue that wires it (issue #67's definition of done).
 */
final class DocumentPresentEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'document_present';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $category = (string) ($gate->configuration()['category'] ?? 'document');

        return GateVerdict::notYetWired(
            "This stage is waiting on a {$category}, and documents arrive in Slice 3.",
            '#104',
        );
    }
}
