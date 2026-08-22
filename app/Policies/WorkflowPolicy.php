<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Workflow;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Workflows, and the three verbs that move them (issues #65, #68, #69, #70).
 *
 * **Advance, override, and skip are three permissions, not one.** IA §7 keeps
 * override and skip apart because they have different audit consequences, and
 * PRD §4.2 F2.2 keeps advance apart from both because advancing is daily work
 * while the other two are decisions somebody should be trusted with
 * separately. An assistant advances stages all day; deciding to proceed
 * without the survey is not the same act.
 */
class WorkflowPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Workflow $workflow): bool
    {
        return $this->belongsToCurrentTeam($workflow)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, Workflow $workflow): bool
    {
        return $this->belongsToCurrentTeam($workflow)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /** The verb is always Advance (IA §7). Never Progress, Move, or Next. */
    public function advance(Person $person, Workflow $workflow): bool
    {
        return $this->belongsToCurrentTeam($workflow)
            && $this->allows($person, Permissions::ADVANCE_WORKFLOW);
    }

    public function override(Person $person, Workflow $workflow): bool
    {
        return $this->belongsToCurrentTeam($workflow)
            && $this->allows($person, Permissions::OVERRIDE_GATE);
    }

    public function skipStage(Person $person, Workflow $workflow): bool
    {
        return $this->belongsToCurrentTeam($workflow)
            && $this->allows($person, Permissions::SKIP_STAGE);
    }
}
