<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Task;
use App\Models\Team;
use App\Support\Notifications\Notify;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * *"Something of mine is due soon"* (F12.4 · issue #101).
 *
 * ## Once a day, in each team's own morning
 *
 * A deadline notification at 3am is the thing F12.4 is complaining about, and
 * quiet hours would hold the email but the *row* would still be stamped in the
 * middle of the night and read as a day early. So the sweep runs hourly and
 * each team is handled only in the hour that is {@see self::HOUR} **local to
 * them** — PRD §9's store-in-UTC, display-in-the-team's-timezone applied to a
 * decision rather than to a rendering.
 *
 * ## Once per task, and the table is what remembers
 *
 * A daily sweep that re-reads the same open task tomorrow would tell somebody
 * about the same deadline every morning until they did it. The guard is a
 * lookup for an existing `deadline_approaching` notification naming this task
 * — the row is the record, so a queue flush or a missed day cannot make it
 * repeat, which is the same reason `notifications.deliver_after` is a column
 * rather than a delayed job.
 */
class NotifyAboutDeadlines extends Command
{
    protected $signature = 'notifications:deadlines';

    protected $description = 'Tell people about their tasks due tomorrow, in their team’s morning';

    /** Local hour, 24h. Early enough to plan the day, late enough to be awake. */
    public const HOUR = 8;

    /** How far ahead counts as *soon*. One day: the reminder is "tomorrow". */
    public const HORIZON_DAYS = 1;

    public function handle(TeamContext $teams, Notify $notify): int
    {
        $told = 0;

        /*
         * `Team::query()` plainly: `teams` is the tenant boundary itself and
         * carries no global scope to lift — `ModelTenancyConventionTest`
         * records it as team-agnostic for exactly that reason. The escape
         * hatch this command needs is the `runFor()` below, not a scope
         * removal that would be a no-op.
         */
        foreach (Team::query()->cursor() as $team) {
            $localHour = (int) Carbon::now()->setTimezone($team->timezone)->format('G');

            if ($localHour !== self::HOUR) {
                continue;
            }

            $told += (int) $teams->runFor($team, fn (): int => $this->sweep($team, $notify));
        }

        $this->components->info($told === 1
            ? 'Told 1 person about a deadline.'
            : "Told {$told} people about deadlines.");

        return self::SUCCESS;
    }

    private function sweep(Team $team, Notify $notify): int
    {
        /*
         * The local day boundary, not `addDay()` on an instant. *"Due
         * tomorrow"* is a date on a calendar somebody is looking at, and
         * twenty-four hours from 08:00 is the wrong window on the day the
         * clocks change — which is precisely the week a reminder is judged on.
         */
        $tomorrow = Carbon::now()->setTimezone($team->timezone)->addDays(self::HORIZON_DAYS)->toDateString();

        $due = Task::query()
            ->open()
            ->whereNotNull('assignee_id')
            ->whereDate('due_date', $tomorrow)
            ->with('deal')
            ->get();

        $told = 0;

        foreach ($due as $task) {
            $already = Notification::query()
                ->where('person_id', $task->assignee_id)
                ->where('type', NotificationType::DeadlineApproaching->value)
                ->where('data->taskId', $task->getKey())
                ->exists();

            if ($already) {
                continue;
            }

            $person = Person::query()->find($task->assignee_id);

            if (! $person instanceof Person) {
                continue;
            }

            $notify->send(
                type: NotificationType::DeadlineApproaching,
                people: [$person],
                team: $team,
                summary: '“'.$task->title.'” is due tomorrow',
                deal: $task->deal,
                data: ['taskId' => $task->getKey()],
            );

            $told++;
        }

        return $told;
    }
}
