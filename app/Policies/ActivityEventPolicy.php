<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityEvent;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * The timeline, and the contact log that writes to it (PRD F2.5, F9.4).
 *
 * There is no `update` or `delete`. Activity is not the audit log and is not
 * immutable by decree, but nothing in Slice 1 edits an event, and a policy
 * method that grants a capability the product does not have is a capability
 * waiting to be used by accident.
 */
class ActivityEventPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_PEOPLE);
    }

    public function view(Person $person, ActivityEvent $event): bool
    {
        return $this->belongsToCurrentTeam($event)
            && $this->allows($person, Permissions::VIEW_PEOPLE);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_PEOPLE);
    }
}
