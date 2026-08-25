<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gate;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class GatePolicy
{
    use ChecksTeamPermissions;

    public function view(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    /**
     * Editing a gate on a running deal — which nothing does yet.
     *
     * This method spent two slices docblocked *"ticking a manual gate is
     * ordinary deal work"* while no route asked for it, and that was the tell
     * that the confirmation path was missing. It is not what ticking uses:
     * `ConfirmGateRequest` asks `WorkflowPolicy::advance`, because clearing a
     * requirement is part of moving a workflow and the person who advances
     * stages all day is the person who confirms the survey came back.
     *
     * Left here rather than deleted because a gate a team may *edit* on a
     * live deal is a real thing Slice 3 will want, and `deals.manage` is the
     * right permission for it. Renamed in the docblock so it stops being a
     * false lead.
     */
    public function update(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /** Proceeding without it is not (S24, #69). */
    public function override(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::OVERRIDE_GATE);
    }
}
