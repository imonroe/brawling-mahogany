<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates;

use App\Models\Gate;

/**
 * One small class per gate type (PRD §8.3 · `CLAUDE.md` · issue #67).
 *
 * > Adding a gate type means adding a class, never touching advancement logic.
 *
 * That is the whole design. `AdvanceWorkflow` knows nothing about inspection
 * reports or required tasks; it asks the registry for an evaluator and reads
 * the verdict. A gate type added in Slice 5 changes one file and adds another.
 */
interface GateEvaluator
{
    /** The `gate_type` value this evaluator answers to. */
    public static function type(): string;

    /**
     * Is this gate satisfied, right now, for this stage?
     *
     * Evaluated fresh every time. `gates.is_met` is a cache for rendering, and
     * a stale cached `true` read at advance time is the failure mode this
     * product cannot have.
     */
    public function evaluate(Gate $gate): GateVerdict;
}
