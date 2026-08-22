<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates;

use App\Models\Gate;
use App\Support\Workflow\Gates\Evaluators\ActionCompletedEvaluator;
use App\Support\Workflow\Gates\Evaluators\ApprovalEvaluator;
use App\Support\Workflow\Gates\Evaluators\DateReachedEvaluator;
use App\Support\Workflow\Gates\Evaluators\DocumentPresentEvaluator;
use App\Support\Workflow\Gates\Evaluators\FieldPopulatedEvaluator;
use App\Support\Workflow\Gates\Evaluators\ManualConfirmationEvaluator;
use App\Support\Workflow\Gates\Evaluators\RequiredTasksCompleteEvaluator;
use Illuminate\Contracts\Container\Container;

/**
 * `gate_type` in, evaluator out (PRD §8.3 · issue #67).
 *
 * The seam that keeps gate evaluation data-driven. `AdvanceWorkflow` asks this
 * for an evaluator and reads a verdict; it has never heard of inspection
 * reports. Adding a gate type in Slice 5 means writing a class and adding one
 * line here.
 *
 * Resolved through the container so an evaluator can take dependencies — the
 * document one will need the storage service, and the date one the key-date
 * calculator.
 */
final class GateRegistry
{
    /** @var list<class-string<GateEvaluator>> */
    private const EVALUATORS = [
        // Live in Slice 2.
        ManualConfirmationEvaluator::class,
        RequiredTasksCompleteEvaluator::class,
        FieldPopulatedEvaluator::class,
        ApprovalEvaluator::class,

        // Registered now, wired in their own slices. Each returns an
        // explanatory unmet naming its issue rather than a silent false, so a
        // template may carry the gate type today and a person reading the
        // advance modal is told why it cannot clear.
        DocumentPresentEvaluator::class,  // Slice 3, #104
        ActionCompletedEvaluator::class,  // Slice 3, #92
        DateReachedEvaluator::class,      // Slice 4, #109
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @throws UnknownGateType
     */
    public function evaluatorFor(string $gateType): GateEvaluator
    {
        foreach (self::EVALUATORS as $evaluator) {
            if ($evaluator::type() === $gateType) {
                /** @var GateEvaluator */
                return $this->container->make($evaluator);
            }
        }

        throw UnknownGateType::for($gateType);
    }

    /**
     * @throws UnknownGateType
     */
    public function evaluate(Gate $gate): GateVerdict
    {
        return $this->evaluatorFor($gate->gate_type)->evaluate($gate);
    }

    /**
     * Every type a template may use.
     *
     * The gate editor (S43) reads this rather than keeping its own list —
     * a picker offering a type the registry cannot resolve builds gates that
     * throw at advance time, which is the worst place to find out.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_map(fn (string $evaluator): string => $evaluator::type(), self::EVALUATORS);
    }
}
