<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforcement layer 4 (ADR 0002): a policy on every model, deny by default.
 *
 * *"Policies check capability; the scope already handled visibility."* That
 * division is why these methods mostly ask about permissions — by the time a
 * policy runs, the global scope has already made another team's records
 * unreachable.
 *
 * `belongsToCurrentTeam()` is the belt to that scope's braces, and every
 * policy here calls it: it closes the one hole the scope cannot, which is a
 * model instance that reached the policy without passing through a scoped
 * query.
 */
trait ChecksTeamPermissions
{
    protected function currentTeam(): ?Team
    {
        return app(TeamContext::class)->get();
    }

    protected function membership(Person $person): ?TeamMembership
    {
        $team = $this->currentTeam();

        if ($team === null) {
            return null;
        }

        $membership = $person->membershipIn($team);

        $membership?->loadMissing('roles.permissions');

        return $membership;
    }

    /**
     * Deny by default: no team, no membership, or a revoked one, is a no.
     */
    protected function allows(Person $person, string $permission): bool
    {
        $membership = $this->membership($person);

        if ($membership === null || $membership->isRevoked()) {
            return false;
        }

        return $membership->hasPermission($permission);
    }

    protected function belongsToCurrentTeam(Model $model): bool
    {
        $team = $this->currentTeam();

        if ($team === null) {
            return false;
        }

        return $model->getAttribute('team_id') === $team->getKey();
    }
}
