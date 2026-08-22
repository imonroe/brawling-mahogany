<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditEntry;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * The audit log has no global scope, so this policy is the whole boundary.
 *
 * There is no `create`, `update`, or `delete`, and their absence is the point:
 * entries are written by App\Support\Audit\AuditLogger and by nothing else,
 * and the table refuses the other two outright.
 */
class AuditEntryPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_AUDIT_LOG);
    }

    public function view(Person $person, AuditEntry $entry): bool
    {
        return $entry->team_id === $this->currentTeam()?->getKey()
            && $this->allows($person, Permissions::VIEW_AUDIT_LOG);
    }
}
