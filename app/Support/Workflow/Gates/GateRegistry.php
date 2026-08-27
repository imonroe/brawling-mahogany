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
        DateReachedEvaluator::class,      // Live in Slice 4, #109
    ];

    /**
     * The types that need no `configuration` to work.
     *
     * Both are answered entirely from the stage they are on: one from
     * `gates.is_met`, which the confirmation route writes, and one from the
     * stage's own required tasks. Everything else needs a field, a role, a
     * category or a date that S43 cannot yet ask for.
     *
     * @var list<string>
     */
    private const CONFIGURATION_FREE = [
        'manual_confirmation',
        'required_tasks_complete',
    ];

    /**
     * Types S43 *can* fully specify, because the editor has a field for them.
     *
     * The list this class's docblock said would grow *"when the editor for a
     * type's configuration does"*. `date_reached` is the first: S43 asks for
     * the name of the key date, which is the whole of its configuration, so a
     * gate composed there is one an evaluator can answer.
     *
     * Its own constant rather than an entry in {@see self::CONFIGURATION_FREE},
     * because the two say different things and only one of them is about the
     * database: a configuration-free type needs nothing stored, and this one
     * needs something the editor now knows how to ask for. Collapsing them
     * would lose the distinction the next configurable type needs.
     *
     * @var list<string>
     */
    private const EDITOR_CONFIGURABLE = [
        'date_reached',
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

    /**
     * Whether S43 has to ask for a key date before this type can be saved.
     *
     * One question rather than a `match` in the controller and a second one in
     * the component: a gate type that needs a configuration and a screen that
     * does not know it is the state `selectableOptions()` exists to prevent,
     * and the answer belongs beside the list that admits the type.
     */
    public static function needsKeyDate(string $gateType): bool
    {
        return $gateType === 'date_reached';
    }

    /**
     * The types S43 can fully specify **today**.
     *
     * Not the same list as `types()`, and the difference is the whole point.
     * Five of the seven evaluators read a `configuration` — a field name, a
     * role, a key date, a document category — and S43 has no editor for any of
     * them. A gate composed without its configuration is one no evaluator can
     * ever answer: `GateVerdict::notYetWired()` says so in its own refusal
     * text, *"it cannot clear on its own — override it with a reason if the
     * deal needs to move."*
     *
     * Which is `CLAUDE.md`'s S17 finding — *"a row nothing can reach is a rule
     * nobody is following"* — reintroduced one layer up. The confirmation
     * route removed it at the runtime layer; a picker offering all seven would
     * hand somebody a two-click way to build a stage that only an **override**
     * can pass, and an override is the audited exception, not a workflow step.
     *
     * `types()` stays the full list, because instantiation and the packs that
     * will carry configured gates need every one of them. This is what a
     * **person choosing from a dropdown** may pick, and it grows when the
     * editor for a type's configuration does.
     *
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        return array_filter(
            self::options(),
            fn (string $type): bool => in_array($type, self::CONFIGURATION_FREE, true)
                || in_array($type, self::EDITOR_CONFIGURABLE, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The same list, as `value => label`, for S43's picker.
     *
     * Not a lookup beside the registry: the label lives on the evaluator, so
     * *"adding a gate type means adding a class"* covers being selectable and
     * being legible at once. Ordered as `EVALUATORS` is — the four that work
     * today first, then the three whose slices have not landed, which is the
     * order somebody choosing one wants them in.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::EVALUATORS as $evaluator) {
            $options[$evaluator::type()] = $evaluator::label();
        }

        return $options;
    }
}
