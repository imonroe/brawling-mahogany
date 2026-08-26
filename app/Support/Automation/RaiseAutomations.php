<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Gate;
use App\Models\MessageTemplate;
use App\Models\Stage;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Messages\MergeContext;
use App\Support\Messages\RecipientRule;
use App\Support\Messages\RenderMessage;
use App\Support\Messages\ResolveRecipients;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A trigger fires, and instances appear (PRD §4.5 F5.1–F5.4, F5.10 · #92).
 *
 * The one place `action_instances` are created. `AdvanceWorkflow` calls it
 * **after its transaction commits**, which PRD §8.1 requires in as many words:
 * an advance must not be slow because a mail provider is slow, and must not
 * roll back after an email has gone.
 *
 * ## The words are rendered here, not at send time
 *
 * F5.10 is the behaviour agents actually notice — *"reaching a milestone
 * pre-fills the relevant outbound email with the right recipient and content,
 * ready to review and send"* — and a message rendered at send time cannot be
 * pre-filled, reviewed, or edited. So the render happens now and lands in
 * `payload`, which is what the approval queue shows and what an approver
 * changes.
 *
 * ## Nothing is dispatched from here
 *
 * This writes rows. `AdvanceWorkflow` dispatches what came back, after the
 * commit. Keeping the two apart is what lets the raise run inside a
 * transaction with the state change it belongs to — a message queued for an
 * advance that then rolled back is the failure PRD §4.5 calls unrecallable.
 */
final class RaiseAutomations
{
    public function __construct(
        private readonly StageAutomations $automations,
        private readonly RenderMessage $renderer,
        private readonly ResolveRecipients $recipients,
        private readonly TeamContext $teams,
    ) {}

    /**
     * @return list<ActionInstance> The instances raised, for the caller to dispatch.
     */
    public function forStage(Stage $stage, AutomationTrigger $trigger): array
    {
        return $this->raiseAll($stage, $this->automations->on($stage, $trigger));
    }

    /**
     * A workflow-level trigger, which still hangs off a stage template.
     *
     * `workflow_start` and `workflow_completion` are facts about the workflow,
     * but an automation is only ever attached to a *stage* template — there is
     * no workflow-level place to put one, and #91 deliberately did not invent
     * a second parent for two triggers. So every stage is asked, and the
     * instance is attributed to the stage that carried it, which is what makes
     * it findable on S16 next to everything else that stage did.
     *
     * @return list<ActionInstance>
     */
    public function forWorkflow(Workflow $workflow, AutomationTrigger $trigger): array
    {
        $workflow->loadMissing('stages');

        $raised = [];

        foreach ($workflow->stages as $stage) {
            $raised = [...$raised, ...$this->forStage($stage, $trigger)];
        }

        return $raised;
    }

    /**
     * F5.2's *when a requirement clears*, narrowed to the one that cleared.
     *
     * The gate is matched by `sort_order`, which is the only key the runtime
     * and definition layers share — `InstantiateWorkflow::gateSortOrder()`
     * argues that at length. An automation whose gate has been deleted from
     * the template carries a null and matches nothing, so it never fires;
     * firing on whichever gate inherited the ordering would be worse.
     *
     * @return list<ActionInstance>
     */
    public function forGate(Gate $gate): array
    {
        $stage = $gate->stage;

        if (! $stage instanceof Stage) {
            return [];
        }

        return $this->raiseAll($stage, array_values(array_filter(
            $this->automations->on($stage, AutomationTrigger::GateCleared),
            fn (SnapshotAutomation $automation): bool => $automation->waitsOnGate($gate->sort_order),
        )));
    }

    /**
     * @param  list<SnapshotAutomation>  $automations
     * @return list<ActionInstance>
     */
    private function raiseAll(Stage $stage, array $automations): array
    {
        if ($automations === []) {
            // The common case by a wide margin, and worth not paying two
            // queries for: most stages carry no automations at all.
            return [];
        }

        $stage->loadMissing('workflow.deal');

        $deal = $stage->workflow?->deal;
        $team = $this->teams->get();

        if (! $deal instanceof Deal || ! $team instanceof Team) {
            return [];
        }

        $raised = [];

        foreach ($automations as $automation) {
            $instance = $this->raise($automation, $stage, $deal, $team);

            if ($instance instanceof ActionInstance) {
                $raised[] = $instance;
            }
        }

        return $raised;
    }

    private function raise(
        SnapshotAutomation $automation,
        Stage $stage,
        Deal $deal,
        Team $team,
    ): ?ActionInstance {
        /*
         * An automation missing its template is skipped rather than raised
         * broken. S44 already draws it as *"needs a template"*, so the state
         * is visible where it can be fixed; putting an empty message in the
         * approval queue would move the problem somewhere nobody can fix it.
         */
        if (! $automation->isComplete()) {
            return null;
        }

        if ($this->alreadyRaised($automation, $stage)) {
            return null;
        }

        $payload = $automation->actionType->needsMessageTemplate()
            ? $this->render($automation, $stage, $deal, $team)
            : [];

        $instance = new ActionInstance;

        $instance->forceFill([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'stage_id' => $stage->getKey(),
            'action_definition_id' => $automation->actionDefinitionId,
            'action_type' => $automation->actionType->value,
            'message_template_id' => $automation->messageTemplateId,
            'config' => $automation->config,
            'trigger' => $automation->trigger->value,
            'state' => $this->openingState($automation, $team)->value,
            'payload' => $payload,
        ])->save();

        return $instance;
    }

    /**
     * Whether this exact automation has already fired on this exact stage.
     *
     * `AdvanceWorkflow::reopen()` wrote this contract before there was a table
     * to hold it: *"an action that already fired stays fired — a client
     * emailed when the stage first completed must not be emailed again on the
     * second advance"*, and *"the dedupe belongs on the sending side, keyed by
     * the stage and the action rather than by a count of advances."* Reopening
     * a completed stage and advancing it again is an ordinary correction —
     * #70's own example is an inspection report coming back with a second
     * issue — and it must not re-tell the client something they were told last
     * week.
     *
     * ## Cancelled rows do not count, and that is the whole subtlety
     *
     * A skipped stage cancels what was queued for it, and nothing went out. If
     * that stage is later reopened and worked properly, the automation *has
     * not fired* and should. Excluding cancelled rows is what tells "already
     * said" apart from "never said", and a naive `exists()` over every row
     * would silence the second case forever.
     *
     * A `failed` row does count. A message that was refused for an unfilled
     * merge field will be refused again for the same reason, and raising a
     * second one only puts a second identical failure on the deal's timeline.
     */
    private function alreadyRaised(SnapshotAutomation $automation, Stage $stage): bool
    {
        return ActionInstance::query()
            ->where('stage_id', $stage->getKey())
            ->where('trigger', $automation->trigger->value)
            ->where('action_type', $automation->actionType->value)
            ->when(
                $automation->actionDefinitionId !== null,
                fn (Builder $query): Builder => $query->where(
                    'action_definition_id',
                    $automation->actionDefinitionId,
                ),
                fn (Builder $query): Builder => $query->whereNull('action_definition_id'),
            )
            ->where('state', '!=', AutomationState::Cancelled->value)
            ->exists();
    }

    /**
     * Where an instance starts, and the one place F5.7's 30-day default is
     * applied.
     *
     * Three ways in, and the team's own window overrides two of them:
     *
     *  - A **manual** automation is a prompt, so it waits for a person
     *    whatever else is true.
     *  - Anything that **reaches outside the team** waits while the team is
     *    inside its first 30 days — *"the safety net for exactly the period
     *    when a team's templates are least tested and their trust is most
     *    fragile"*. The automation's own setting cannot opt out of it, which
     *    is the difference between a default and a suggestion.
     *  - Otherwise the automation decides.
     *
     * A `create_task` is not held: it reaches nobody, and a queue full of
     * task-creations waiting for approval would teach people to approve
     * without reading, which is the failure mode #93 warns about for bulk
     * approve.
     */
    private function openingState(SnapshotAutomation $automation, Team $team): AutomationState
    {
        if ($automation->isManual) {
            return AutomationState::AwaitingApproval;
        }

        $leavesTheBuilding = $automation->actionType === AutomationActionType::SendEmail;

        if ($leavesTheBuilding && $team->approvalIsMandatory()) {
            return AutomationState::AwaitingApproval;
        }

        return $automation->requiresApproval
            ? AutomationState::AwaitingApproval
            : AutomationState::Pending;
    }

    /**
     * The rendered message and the addresses it would go to, snapshotted.
     *
     * Addresses rather than a rule, and this is the one place in the product
     * that is true: PRD §7.12's correction is that a *template* must not hold
     * an address, because a template is reused. An instance is one message to
     * one set of people on one deal, and recording who it was for is what lets
     * S49 answer *"did the client get told"* months later — after the
     * participant list has changed.
     *
     * @return array<string, mixed>
     */
    private function render(
        SnapshotAutomation $automation,
        Stage $stage,
        Deal $deal,
        Team $team,
    ): array {
        $template = MessageTemplate::query()->whereKey($automation->messageTemplateId)->first();

        if (! $template instanceof MessageTemplate) {
            /*
             * Reachable: a template soft-deleted between the snapshot and the
             * trigger. The instance is still raised — a milestone did happen —
             * and it is refused at the send path with a reason somebody can
             * read, rather than vanishing.
             */
            return ['recipients' => [], 'unknown' => ['the message template has been removed']];
        }

        $rendered = $this->renderer->render(
            $template,
            MergeContext::for($deal, $team, $stage),
        );

        $rule = RecipientRule::tryFromArray($template->recipient_rule);

        return [
            ...$rendered->toArray(),
            'templateName' => $template->name,
            'recipients' => $rule === null
                ? []
                : $this->addresses($this->recipients->for($rule, $deal, $team)),
        ];
    }

    /**
     * @param  Collection<int, TeamMembership>  $memberships
     * @return list<array{name: string, email: string}>
     */
    private function addresses(Collection $memberships): array
    {
        $addresses = $memberships
            /*
             * A participant with no email address is dropped here rather than
             * failing the send. A deal's roster legitimately holds people
             * nobody has an address for — an opposing agent somebody noted
             * down by name — and one of them must not stop the seller's email.
             * An instance that ends up with *nobody* is refused by the rails
             * with a reason, which is the case that matters.
             */
            ->filter(fn (TeamMembership $membership): bool => ($membership->email ?? '') !== '')
            ->map(fn (TeamMembership $membership): array => [
                'name' => $membership->fullName(),
                'email' => (string) $membership->email,
            ])
            ->values()
            /*
             * `all()` after `values()` is typed `array<int, T>` rather than a
             * list, and the payload's shape promises a list — so the promise
             * is made true here rather than widened in the docblock.
             */
            ->all();

        return array_values($addresses);
    }
}
