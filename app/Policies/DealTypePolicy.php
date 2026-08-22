<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DealType;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Deal types (S76 · issue #58).
 *
 * A lookup with no `team_id` scope, so `belongsToCurrentTeam()` cannot do the
 * work here — the checks are written out. A **system** type belongs to nobody
 * and is editable by nobody: hiding "Rental Placement" for every team on the
 * platform because one team stopped doing rentals is not what that team asked
 * for.
 */
class DealTypePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function view(Person $person, DealType $dealType): bool
    {
        return $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    /**
     * An archived type is not editable either.
     *
     * The screen already hid the Edit button on one, but the screen was the
     * only thing hiding it — and this is the table whose own documentation
     * says the policy and the controller are all that stand here, because
     * there is no global scope behind them. A renamed archived type is also a
     * name freed and re-taken behind the validator's back.
     */
    public function update(Person $person, DealType $dealType): bool
    {
        return $dealType->isManageableByTeam()
            && $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    /**
     * Archive, never delete — a type deals point at cannot be removed without
     * orphaning them (S76's in-use warning).
     */
    public function archive(Person $person, DealType $dealType): bool
    {
        return $dealType->isManageableByTeam()
            && $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    /**
     * Its own ability, not a second use of `archive`.
     *
     * `isArchivable()` is false for a type that is *already* archived — which
     * is exactly the row somebody restores, so reusing `archive` here made the
     * undo unreachable and turned archiving into the one-way door it exists to
     * avoid being.
     */
    public function restore(Person $person, DealType $dealType): bool
    {
        return $dealType->isRestorable()
            && $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    /**
     * Ours, or the shared kind. Never another team's.
     *
     * A foreign row should never reach this — `DealType::resolveRouteBinding()`
     * turns one into a 404 before any policy runs, because a 403 confirms the
     * record exists (ADR 0002, layer 3). This stays as the second line: the
     * binder covers route-bound checks and a policy can also be asked about a
     * model somebody loaded by hand.
     */
    private function visibleHere(DealType $dealType): bool
    {
        return $dealType->isSystem()
            || $dealType->team_id === $this->currentTeam()?->getKey();
    }
}
