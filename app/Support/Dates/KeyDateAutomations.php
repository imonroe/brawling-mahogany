<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Stage;
use App\Models\Team;
use App\Support\Automation\RaiseAutomations;
use App\Support\Automation\StageAutomations;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What a moved date does to the messages queued behind it (issue #106, F5.3).
 *
 * #106 names this as part of the cascade rather than as a follow-up: *"pending
 * `action_instances` scheduled off a moved date (#92) — reschedule or
 * cancel."* Getting it wrong is not a cosmetic bug. An email that says *"your
 * inspection objection deadline is Friday"* going out on the old schedule
 * after the deadline moved to the following Wednesday is worse than no email,
 * because the client acts on it.
 *
 * ## Rescheduling, never re-raising
 *
 * A message that has already **gone** stays gone: `sent` and `failed` are
 * records of what happened, and rewriting one because a date later moved would
 * make the deal's history a description of the present. Only rows that have
 * not left — `pending` and `awaiting_approval`, with no `message_key` claimed
 * — have their `scheduled_for` moved.
 *
 * The `message_key` check is the same one `AdvanceWorkflow::skip()` makes and
 * for the same reason: a claimed key means a worker has already handed the
 * message to a transport, and nothing outside `automations:reap-unconfirmed`
 * may decide that row's fate.
 *
 * ## Why the queue is not rendered again
 *
 * `action_instances.payload` is a snapshot of the words at raise time, and
 * F5.10 depends on that: what an approver reads on S48 *is* the payload. A
 * date moving does not re-render it, which means a message whose body names
 * the old date is queued with the old date in it.
 *
 * That is deliberate and it is the safe direction. Re-rendering would silently
 * change words a person may already have approved — the defect
 * `ApproveMessage` refuses tokens for, arriving from the other end. What moves
 * is *when* it goes; if the words are now wrong, S47 shows the message and the
 * queue is where somebody cancels it.
 */
final class KeyDateAutomations
{
    /**
     * The hour, team-local, a key-date automation goes out on its day.
     *
     * The same eight o'clock `NotifyAboutDeadlines` uses, and for the same
     * argument: early enough to be the first thing somebody sees, late enough
     * that it is not a message sent in the middle of the night. A key-date
     * automation usually reaches a *client*, which makes the hour matter more
     * rather than less.
     */
    public const HOUR = 8;

    public function __construct(
        private readonly RaiseAutomations $raiser,
        private readonly StageAutomations $automations,
        private readonly TeamContext $teams,
    ) {}

    /**
     * Bring the queue into line with where these dates now are.
     *
     * Called with the date that was edited **and** everything the cascade
     * moved, because a client email hangs off the objection deadline as
     * readily as off the closing date that dragged it.
     *
     * @param  list<KeyDate>  $keyDates
     * @return list<ActionInstance>
     */
    public function reschedule(array $keyDates, Deal $deal): array
    {
        if ($keyDates === []) {
            return [];
        }

        $stages = $this->stagesOf($deal);

        if ($stages === []) {
            return [];
        }

        $team = $this->teams->get();
        $timezone = $team instanceof Team ? $team->timezone : (string) config('app.timezone');

        $raised = [];

        foreach ($keyDates as $keyDate) {
            /*
             * A proposal is not a date (#116). Scheduling a client email off
             * an extracted deadline nobody has agreed to would put the
             * machine's reading of a contract in front of a customer, which
             * PRD §4.10 forbids in as many words.
             */
            if ($keyDate->isPending()) {
                continue;
            }

            $moved = $this->moveQueued($keyDate, $deal, $timezone);

            if ($moved > 0) {
                continue;
            }

            $raised = [...$raised, ...$this->raiser->forKeyDate(
                $keyDate,
                $stages,
                $this->fireAt($keyDate, $stages, $timezone),
            )];
        }

        /*
         * Dispatched by the caller, after its transaction commits — the
         * boundary `AdvanceWorkflow::dispatchRaised()` established. Returned
         * rather than queued here for exactly that reason.
         */
        return $raised;
    }

    /**
     * A date is gone: nothing queued off it should go out.
     *
     * Cancelled rather than deleted, and with a reason a person can read on
     * S47. The distinction `SendDecision` draws applies here too — *"already
     * sent"* and *"never sent"* are different facts, and a cancelled row is
     * how the second one is recorded.
     */
    public function cancelFor(KeyDate $keyDate): int
    {
        return $this->queuedFor($keyDate)->update([
            'state' => AutomationState::Cancelled->value,
            'error' => 'The date this was scheduled from was removed before the message went out.',
            'updated_at' => now(),
        ]);
    }

    /**
     * Move what is already queued, and say how many rows moved.
     *
     * Zero means nothing is queued for this date yet, which is what tells
     * `reschedule()` to raise rather than update — the same question
     * `RaiseAutomations::alreadyRaised()` asks one layer down, asked here
     * because an `UPDATE` returning a count answers it in the round trip that
     * was needed anyway.
     */
    private function moveQueued(KeyDate $keyDate, Deal $deal, string $timezone): int
    {
        $stages = $this->stagesOf($deal);

        $moved = 0;

        foreach ($this->queuedFor($keyDate)->get() as $instance) {
            $stage = $instance->stage;

            $at = $this->fireAt(
                $keyDate,
                $stage instanceof Stage ? [$stage] : $stages,
                $timezone,
            );

            $instance->forceFill(['scheduled_for' => $at])->save();

            $moved++;
        }

        return $moved;
    }

    /**
     * Everything queued off this date that has not left the building.
     *
     * Matched on the instance's own `config->keyDateName`, folded the way
     * `SnapshotAutomation::namesKeyDate()` folds it — the automation's config
     * is copied onto the instance at raise time, so the pointer is already
     * there and does not need a column of its own.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ActionInstance>
     */
    private function queuedFor(KeyDate $keyDate): \Illuminate\Database\Eloquent\Builder
    {
        return ActionInstance::query()
            ->where('deal_id', $keyDate->deal_id)
            ->where('trigger', AutomationTrigger::KeyDateOffset->value)
            ->whereIn('state', [
                AutomationState::Pending->value,
                AutomationState::AwaitingApproval->value,
            ])
            ->whereNull('message_key')
            ->whereRaw(
                "lower(trim(config->>'keyDateName')) = ?",
                [mb_strtolower(trim($keyDate->name))],
            );
    }

    /**
     * The instant a date's automation fires.
     *
     * The **day** comes from the key date plus the automation's own signed
     * offset; the **hour** comes from the team's wall clock. Both halves
     * matter: PRD §9 stores UTC and displays the team's zone, and an instant
     * computed by adding hours to a UTC midnight is an hour wrong twice a
     * year — in exactly the fortnight a contingency deadline is most likely to
     * be argued about.
     *
     * ## A date already past fires now
     *
     * Somebody entering a closing date retrospectively, or moving one
     * backwards, produces a `scheduled_for` in the past. `ActionInstance::
     * scopeDue()` treats that as due, which is right: the alternative is a row
     * that sits `pending` forever with nothing to pick it up.
     *
     * ## The offset is read from the first automation that names this date
     *
     * Two automations on one deal offset differently from the same date is a
     * real configuration, and each raises its own instance through
     * `RaiseAutomations::forKeyDate()`. What this cannot do is give one
     * instant to rows that want two — so it takes the first match and the
     * per-instance move below re-asks with that instance's own stage.
     *
     * @param  list<Stage>  $stages
     */
    private function fireAt(KeyDate $keyDate, array $stages, string $timezone): CarbonInterface
    {
        $offset = $this->offsetDaysFor($keyDate, $stages);

        $day = CarbonImmutable::parse($keyDate->date->toDateString(), $timezone)
            ->startOfDay()
            ->addDays($offset)
            ->setTime(self::HOUR, 0);

        return $day->utc();
    }

    /**
     * @param  list<Stage>  $stages
     */
    private function offsetDaysFor(KeyDate $keyDate, array $stages): int
    {
        foreach ($stages as $stage) {
            foreach ($this->automations->on($stage, AutomationTrigger::KeyDateOffset) as $automation) {
                if ($automation->namesKeyDate($keyDate->name)) {
                    return $automation->keyDateOffsetDays();
                }
            }
        }

        return 0;
    }

    /**
     * Every stage of every workflow on this deal.
     *
     * An automation hangs off a stage template even when what it reacts to is
     * a fact about the deal — the argument `RaiseAutomations::forWorkflow()`
     * makes about `workflow_start`, and true here for the same reason: there
     * is no deal-level place to attach one and #91 deliberately did not invent
     * a second parent.
     *
     * @return list<Stage>
     */
    private function stagesOf(Deal $deal): array
    {
        return $this->stages[$deal->getKey()] ??= array_values(Stage::query()
            ->whereIn(
                'workflow_id',
                DB::table('workflows')
                    ->where('deal_id', $deal->getKey())
                    ->whereNull('deleted_at')
                    ->select('id'),
            )
            ->with('workflow.deal')
            ->get()
            ->all());
    }

    /**
     * Stages already read this request, keyed by deal.
     *
     * A cascade touching eleven dates asks the same question eleven times, and
     * the answer cannot change inside one save — the memoisation `Notify`
     * makes for preferences, for the same reason and with the same scope.
     *
     * @var array<string, list<Stage>>
     */
    private array $stages = [];
}
