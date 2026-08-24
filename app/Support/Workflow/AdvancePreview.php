<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;

/**
 * What S23 shows before anybody presses Advance (issue #77 · PRD §5.4).
 *
 * ## The standard this screen is held to
 *
 * Screen Inventory, on why S23 is one of the fifteen hard ones: *"Must explain
 * refusal clearly enough to act on."* And #77: this is where the product's
 * central promise — *"make it impossible to silently skip a required step"* —
 * either reads as helpful or reads as an obstruction, and the difference is
 * entirely in how well the refusal explains itself.
 *
 * So the payload is not a boolean and a count. Every gate on the stage is
 * here, each with the sentence its own evaluator wrote and the link target
 * that clears it, and so is a description of what advancing will actually do.
 *
 * ## "What happens when you advance" is data, not copy
 *
 * Design System §7.4 specifies the block and then says the thing worth
 * repeating: *"Never ship the advance action without this block. An automation
 * that emails the wrong client cannot be recalled, and this is the last place
 * a human can catch it."* It fixes four entries in a fixed order — emails,
 * tasks, calendar events, stage completion — so all four are built here rather
 * than in the dialog, which means a test can read them.
 *
 * Two of the four have no data in Slice 2, and they say so **naming the slice
 * that fills them** rather than being omitted. An absent Emails row reads as
 * "no emails will be sent", which is true today and will silently stop being
 * true; a row that says automations arrive in Slice 3 stays honest and turns
 * into the recipient list when #92 lands.
 *
 * ## A presenter, like `DealHeader`
 *
 * It decides nothing and writes nothing — it reads a workflow that is already
 * in memory and names its parts. `DescribeBlockers` supplies the readiness,
 * and is itself pure; nothing on this path mutates the record it describes,
 * which is the rule S15 established (`CLAUDE.md`: "reading is not advancing").
 */
final class AdvancePreview
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Workflow $workflow, Stage $stage, StageReadiness $readiness): array
    {
        $next = $workflow->stageAfter($stage);
        $position = $workflow->stages->values()->search(fn (Stage $each): bool => $each->is($stage));

        return [
            'workflowId' => $workflow->getKey(),
            'workflowName' => $workflow->name,
            'stage' => [
                'id' => $stage->getKey(),
                'name' => $stage->name,
                'state' => $stage->state->value,
                'position' => is_int($position) ? $position + 1 : null,
                'total' => $workflow->stages->count(),
            ],
            'nextStage' => $next instanceof Stage
                ? ['id' => $next->getKey(), 'name' => $next->name]
                : null,
            /*
             * One of #77's five key states, and the only one that is a fact
             * about the workflow rather than about its gates. Advancing the
             * last stage completes the workflow, which is not undoable in a
             * tidy way — `Workflow::stateTransitions()` makes `completed`
             * terminal on purpose — so the dialog has to say so before the
             * click rather than toast it after.
             */
            'isLastStage' => ! $next instanceof Stage,
            'gates' => $readiness->checklist(),
            'counts' => $readiness->counts(),
            'canAdvance' => $readiness->canAdvance(),
            'consequences' => self::consequences($workflow, $stage, $next),
        ];
    }

    /**
     * §7.4's four entries, in §7.4's order.
     *
     * @return list<array<string, string|null>>
     */
    private static function consequences(Workflow $workflow, Stage $stage, ?Stage $next): array
    {
        return [
            self::emails($stage),
            self::tasks($stage, $next),
            self::calendar(),
            self::completion($workflow, $stage, $next),
        ];
    }

    /**
     * Who will be emailed (PRD §4.5, and #77's own emphasis).
     *
     * *"An automation that emails the wrong client cannot be recalled."* There
     * are no automations in Slice 2 — `action_definitions` is #92 — so the
     * honest answer is that nothing is sent, and the row says which slice
     * changes that rather than leaving a reader to infer it from silence.
     *
     * A milestone is the one thing that is already true: `stage.advanced`
     * writes the client-facing sentence to the timeline today (IA §9), and
     * Slice 3 turns that same sentence into the message. Naming it here is
     * what makes the eventual recipient list a change to this method rather
     * than a surprise.
     *
     * @return array<string, string|null>
     */
    private static function emails(Stage $stage): array
    {
        $announcement = $stage->clientAnnouncement();

        if ($announcement === null) {
            return [
                'kind' => 'emails',
                'label' => 'Nobody is emailed',
                'detail' => 'Automated messages arrive in slice 3. Nothing leaves this system today.',
            ];
        }

        return [
            'kind' => 'emails',
            'label' => 'Nobody is emailed yet',
            'detail' => 'This is a milestone, so “'.$announcement.'” is recorded for the client. '
                .'Sending it is slice 3’s work; today it goes to the timeline and no further.',
        ];
    }

    /**
     * What work this moves, in both directions.
     *
     * The open tasks left behind matter more than the ones ahead, and are the
     * half a reader does not expect: advancing does not close them, and a
     * stage that completes with four open tasks on it is four jobs that just
     * fell off the current stage. F4.10 keeps tasks and gates apart on purpose
     * — *"a task is work owed; a gate is a condition on advancement"* — so an
     * open task never blocks unless a gate says it does, and this is the only
     * place anybody is told.
     *
     * @return array<string, string|null>
     */
    private static function tasks(Stage $stage, ?Stage $next): array
    {
        $leftOpen = Task::query()->open()->where('stage_id', $stage->getKey())->count();

        $sentences = [];

        if ($next instanceof Stage) {
            $ahead = Task::query()->open()->where('stage_id', $next->getKey())->count();

            if ($ahead > 0) {
                $sentences[] = self::countOf($ahead, 'task').' on '.$next->name.' becomes current work.';
            }
        }

        if ($leftOpen > 0) {
            $sentences[] = self::countOf($leftOpen, 'task').' on '.$stage->name
                .' stays open — advancing does not close it.';
        }

        return [
            'kind' => 'tasks',
            'label' => $sentences === [] ? 'No tasks change hands' : 'Tasks move',
            'detail' => $sentences === []
                ? 'Nothing is open on this stage, and nothing is waiting on the next one.'
                : implode(' ', $sentences),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function calendar(): array
    {
        return [
            'kind' => 'calendar',
            'label' => 'No calendar events',
            'detail' => 'Dates & Deadlines and the calendar arrive in slice 4, and nothing writes '
                .'to a calendar before then.',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function completion(Workflow $workflow, Stage $stage, ?Stage $next): array
    {
        if (! $next instanceof Stage) {
            return [
                'kind' => 'completion',
                'label' => $stage->name.' completes, and so does '.$workflow->name,
                'detail' => 'This is the last stage. A completed workflow does not reopen — '
                    .'reopening works one stage at a time, and that arrives later.',
            ];
        }

        return [
            'kind' => 'completion',
            'label' => $stage->name.' completes and '.$next->name.' begins',
            'detail' => 'Today’s date is recorded as the actual end of '.$stage->name.'.',
        ];
    }

    /**
     * A count and its noun, for a sentence assembled on the server.
     *
     * IA §10 puts formatting in `lib/formatters.ts` and nowhere else, and that
     * rule is about **values** a screen renders — a date, a name, an address,
     * an amount. These are whole sentences whose subject is a number, and the
     * alternative is shipping the four counts and four sentence templates to
     * the client so it can reassemble them.
     */
    private static function countOf(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
