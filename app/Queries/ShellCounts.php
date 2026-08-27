<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Models\Notification;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Every badge the shell carries, in **one** round trip.
 *
 * ## Why this exists at all
 *
 * `PeopleIndexBudgetTest` said it in advance, before there was a third badge
 * to add: *"Two is where the shell's counts stop being free. A third would
 * need a different mechanism — one query returning several counts — rather
 * than a third line in `HandleInertiaRequests`, and this budget is the thing
 * that should force that conversation rather than a reviewer noticing."*
 *
 * S08's unread count (#101) is the third. So this is the different mechanism,
 * and the budget did the job it was written for: raising the number by one
 * would have been the easy diff and the wrong one, because a fourth badge
 * would then have raised it again.
 *
 * ## Three scalar subqueries, not three queries
 *
 * Postgres evaluates each independently against its own index, and the cost
 * this saves is the **round trip** rather than the work — three counts on a
 * shell rendered on every page is three network waits, on every page.
 *
 * Each subquery is built from the same model layer the individual counts used,
 * so the global team scope still applies where it applied before and does not
 * where it did not. `notifications` is counted **across teams** on purpose
 * (issue #101) and the other two are not, so hand-writing the SQL would have
 * quietly flattened that distinction.
 *
 * ## `toBase()`, never `getQuery()`
 *
 * They look interchangeable and are not: `getQuery()` hands back the
 * underlying base builder with the Eloquent **global scopes not yet applied**,
 * while `toBase()` calls `applyScopes()` first. Written with `getQuery()`,
 * this counted every team's pending messages on every team's shell — a
 * cross-tenant read on the busiest query in the product, sitting under a
 * comment claiming the scopes were preserved.
 *
 * `ActionInstanceIsolationTest` caught it, which is the layer ADR 0002 says
 * should: the guard was not a person reading the diff.
 *
 * The one place the scope is genuinely absent — `Notification::forPerson()` —
 * lifts it inside the model with its own recorded reason, so `toBase()` is
 * right there too: it applies what is there, and there is nothing to apply.
 */
final class ShellCounts
{
    /**
     * @return array{myWork: int, pendingMessages: int, notifications: int}
     */
    public static function for(Person $person): array
    {
        $row = DB::query()
            ->selectSub(MyWork::assigned($person)->open()->toBase()->selectRaw('count(*)'), 'my_work')
            ->selectSub(
                ActionInstance::query()
                    ->where('state', AutomationState::AwaitingApproval->value)
                    ->toBase()
                    ->selectRaw('count(*)'),
                'pending_messages',
            )
            ->selectSub(
                Notification::query()->forPerson($person)->unread()->toBase()->selectRaw('count(*)'),
                'notifications',
            )
            ->first();

        return [
            'myWork' => (int) ($row->my_work ?? 0),
            'pendingMessages' => (int) ($row->pending_messages ?? 0),
            'notifications' => (int) ($row->notifications ?? 0),
        ];
    }
}
