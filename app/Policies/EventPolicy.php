<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Events and the calendar (PRD §4.8 F8.1 · S57, S58 · issue #105).
 *
 * ## Reading is `calendar.view`; writing is `deals.manage`
 *
 * The split looks odd until you ask who each is for. `calendar.view` is
 * already in the catalogue and already gates the sidebar entry — it is the
 * *screen's* key, and PRD §4.2's Read Only role is meant to hold it: a broker
 * watching the pipeline should be able to see the week without being able to
 * move a closing appointment.
 *
 * There is no `calendar.manage`, and inventing one here would put a key in the
 * catalogue that no shipped role holds — the argument `TaskPolicy` makes at
 * length. Every event this product creates is work on a deal or a property,
 * so creating and editing one is deal work.
 *
 * ## Why not `properties.manage` for an open house
 *
 * Because an event's permission cannot depend on which of its two nullable
 * pointers happens to be set. `DocumentPolicy` shipped that shape and it
 * broke: a role with `deals.view` and not `properties.view` got a tab listing
 * files it then refused to download. One key, asked once.
 */
class EventPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_CALENDAR);
    }

    public function view(Person $person, Event $event): bool
    {
        return $this->belongsToCurrentTeam($event)
            && $this->allows($person, Permissions::VIEW_CALENDAR);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, Event $event): bool
    {
        return $this->belongsToCurrentTeam($event)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function delete(Person $person, Event $event): bool
    {
        return $this->update($person, $event);
    }
}
