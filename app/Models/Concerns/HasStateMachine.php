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
     * The map holds however the attribute was written.
     *
     * `transitionTo()` is the door, and a door only works on people who use
     * it. `$stage->setAttribute('state', 'complete')`, `$stage->state =
     * StageState::Complete`, and `->forceFill(['state' => …])` all reach the
     * column without passing the map — adversarial review proved the first of
     * those puts a `pending` stage straight to `complete` with nothing
     * checked. The trait's own docblock argues that a rule enforced at call
     * sites is enforced at some call sites; this is that argument applied to
     * the trait itself.
     *
     * A record being created is exempt, because there is no previous state for
     * a transition to be illegal *from*. That is how a workflow gets its
     * opening state and how every factory works.
     */
    public static function bootHasStateMachine(): void
    {
        static::saving(function (self $model): void {
            $column = static::stateColumn();

            if (! $model->exists || ! $model->isDirty($column)) {
                return;
            }

            $from = $model->getRawOriginal($column);
            $to = $model->getAttribute($column);
            $to = $to instanceof BackedEnum ? (string) $to->value : (string) $to;

            if ($from === null || (string) $from === $to) {
                return;
            }

            if (! in_array($to, static::stateTransitions()[(string) $from] ?? [], true)) {
                throw IllegalStateTransition::between(static::class, (string) $from, $to);
            }
        });
    }

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
