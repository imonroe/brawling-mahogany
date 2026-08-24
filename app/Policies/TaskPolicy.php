<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\Person;
use App\Models\Task;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Tasks (PRD §4.4 F4.10 · S17, S27 · issues #65, #71).
 *
 * The deal's permissions, not a pair of its own. There is no `tasks.view` or
 * `tasks.manage` in `App\Support\Permissions`, and inventing one here would
 * put a key in the catalogue that no shipped role holds — so a Team Member
 * would lose the ability to tick a box on the day the screen shipped.
 *
 * A task is work on a deal: seeing it is seeing the deal (`deals.view`), and
 * completing, adding, editing or deleting one is deal work (`deals.manage`).
 * PRD §4.2 F2.2's Read Only role is the reason the two are separate — a
 * broker who watches the pipeline must be able to read the checklist without
 * being able to tick it.
 *
 * ## `viewAny` and `create` take the deal
 *
 * Both are asked *about a deal* — S17 is a tab on one, and S27 adds to one —
 * and the deal is what carries the team. `DealPropertyPolicy` and
 * `DealParticipantPolicy` take it for the same reason: without it the ability
 * answers "may this person add tasks anywhere" and the controller is left to
 * check the deal itself, which is the check that gets forgotten.
 */
class TaskPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Task $task): bool
    {
        return $this->belongsToCurrentTeam($task)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /**
     * Editing, and completing.
     *
     * Completing is deliberately not its own ability. It is a different *act*
     * — its own route, its own method on `DealTasks`, its own activity event —
     * but it is not a different *permission*: there is no role in PRD §4.2
     * that should be able to tick a box and not to write a due date, and a key
     * that no role distinguishes is a key that only makes the catalogue
     * longer.
     */
    public function update(Person $person, Task $task): bool
    {
        return $this->belongsToCurrentTeam($task)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function delete(Person $person, Task $task): bool
    {
        return $this->update($person, $task);
    }
}
