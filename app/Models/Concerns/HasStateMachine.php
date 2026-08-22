<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\States\IllegalStateTransition;
use BackedEnum;

/**
 * A state column that refuses to hold a state it could not have reached.
 *
 * Issues #59 and #65 both ask for the same thing in the same words —
 * *"transitions are enforced by the model, not by controllers; an illegal
 * transition throws"* — so it is written once here.
 *
 * ## Why the model rather than a service
 *
 * Slice 1 learned this the expensive way. A rule enforced at call sites is a
 * rule with one chance to be forgotten per call site, and the shared-person
 * identity rule was enforced at two of three for a whole review round. The
 * model is the one place every writer passes.
 *
 * That does **not** make this an alternative to `AdvanceWorkflow` (#68). This
 * says which transitions are *possible*; `AdvanceWorkflow` decides whether one
 * is *permitted* — gates, authorisation, ordering, audit. A state machine that
 * lets you move from `active` to `complete` says nothing about whether the
 * gates were met. Both are needed, and #65's own definition of done keeps them
 * apart: no controller may write `stages.state` even though the model would
 * accept the value.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasStateMachine
{
    /**
     * The states reachable from each state.
     *
     * A state absent from the map is terminal. An empty list is also terminal,
     * and means the same thing — the distinction is not worth a second concept.
     *
     * @return array<string, list<string>>
     */
    abstract public static function stateTransitions(): array;

    /** The column holding the state. Overridden by nothing so far. */
    public static function stateColumn(): string
    {
        return 'state';
    }

    /**
     * Move to a new state, or throw saying why it was impossible.
     *
     * Deliberately not a save: the caller decides when to persist, because a
     * transition is almost always one part of a larger transaction — advancing
     * a stage also stamps `actual_end` and `completed_by`, and two writes where
     * one would do is two chances to half-succeed.
     */
    public function transitionTo(BackedEnum|string $state): static
    {
        $target = $state instanceof BackedEnum ? (string) $state->value : $state;
        $current = $this->currentState();

        if ($target === $current) {
            return $this;
        }

        if (! in_array($target, static::stateTransitions()[$current] ?? [], true)) {
            throw IllegalStateTransition::between(static::class, $current, $target);
        }

        $this->setAttribute(static::stateColumn(), $target);

        return $this;
    }

    /** Would that transition be allowed, without attempting it? */
    public function canTransitionTo(BackedEnum|string $state): bool
    {
        $target = $state instanceof BackedEnum ? (string) $state->value : $state;

        return $target === $this->currentState()
            || in_array($target, static::stateTransitions()[$this->currentState()] ?? [], true);
    }

    /**
     * Everywhere this record could go next.
     *
     * The advance modal (S23) and the deal header both need this to decide
     * which actions to offer, and asking the model beats a screen keeping its
     * own copy of the diagram.
     *
     * @return list<string>
     */
    public function availableTransitions(): array
    {
        return static::stateTransitions()[$this->currentState()] ?? [];
    }

    private function currentState(): string
    {
        $value = $this->getAttribute(static::stateColumn());

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
