<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActionInstance;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Queued and sent messages (S47, S48, S49 · issue #93).
 *
 * ## Two permissions, not one, and the split is F5.7's
 *
 * **Reading** the queue takes `deals.view`: a row here is a message about a
 * deal, it names a client and carries their address, and it appears on the
 * deal's own timeline. Anybody who can open the deal can already see all of
 * that.
 *
 * **Releasing** one takes `message.approve`, which the permission catalogue
 * has carried since Slice 1 waiting for this screen. PRD §4.5 is the reason
 * the two are not the same key: approving is the moment somebody takes
 * responsibility for something reaching a client, and a team that wants
 * juniors reading the queue without releasing from it is exactly what a
 * composed role is for.
 *
 * Cancelling sits with approving rather than with reading. Stopping a message
 * is a decision about what the client is told, and F5.8's stop control is
 * a safety rail — but a rail somebody without the responsibility can pull is
 * one that turns "the client was not told" into a thing anybody can cause
 * silently.
 */
class ActionInstancePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, ActionInstance $instance): bool
    {
        return $this->belongsToCurrentTeam($instance)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    /**
     * The same question with no row in hand, for the screen's own affordances.
     *
     * S47 has to decide whether to draw an Approve button before it knows
     * which message somebody will open, and asking `approve()` about whichever
     * row happens to be first would make the button's presence depend on the
     * queue's contents — including answering *no* for an empty queue, which is
     * a different sentence from *"you may not approve"*.
     */
    public function approveAny(Person $person): bool
    {
        return $this->viewAny($person) && $this->allows($person, Permissions::APPROVE_MESSAGE);
    }

    public function approve(Person $person, ActionInstance $instance): bool
    {
        return $this->view($person, $instance)
            && $this->allows($person, Permissions::APPROVE_MESSAGE);
    }

    public function cancel(Person $person, ActionInstance $instance): bool
    {
        return $this->approve($person, $instance);
    }
}
