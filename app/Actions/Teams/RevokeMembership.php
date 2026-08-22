<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\SystemRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Revoke somebody's access without erasing what they did (PRD F1.3).
 *
 * *"Revoke without destroying historical attribution."* So this sets
 * `revoked_at` and never deletes: every activity event, task completion, and
 * audit entry that person authored still carries their name.
 */
final class RevokeMembership
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException when this would leave the team unadministrable
     */
    public function handle(TeamMembership $membership): void
    {
        $this->guardLastOwner($membership);

        $membership->revoke();

        $this->audit->record(
            action: 'membership.revoked',
            auditable: $membership,
            teamId: $membership->team_id,
            after: ['person_id' => $membership->person_id],
        );
    }

    /**
     * A team must always keep one Team Owner who can actually sign in.
     *
     * Issue #45: the refusal comes *"with copy that explains why — not a
     * generic validation error."* A team whose last owner is revoked cannot
     * invite anybody, cannot change its settings, and cannot recover without
     * the platform operator.
     */
    public function guardLastOwner(TeamMembership $membership): void
    {
        $membership->loadMissing('roles');

        if (! $membership->hasRole(SystemRole::TeamOwner->value)) {
            return;
        }

        if ($this->otherOwnerCount($membership) > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'membership' => 'This is the team’s last owner. Give somebody else the Team Owner role first, '.
                'or the team will have nobody who can manage members, settings, or billing.',
        ]);
    }

    private function otherOwnerCount(TeamMembership $membership): int
    {
        return TeamMembership::query()
            ->where('team_id', $membership->team_id)
            ->whereKeyNot($membership->getKey())
            ->whereNull('revoked_at')
            ->whereHas('roles', fn ($query) => $query->where('roles.key', SystemRole::TeamOwner->value))
            ->whereHas('person', fn ($query) => $query->whereNotNull('password'))
            ->count();
    }

    /**
     * The same rule, asked before a role change rather than a revocation.
     */
    public function guardLastOwnerRoleChange(Team $team, TeamMembership $membership, bool $keepsOwnerRole): void
    {
        if ($keepsOwnerRole) {
            return;
        }

        $this->guardLastOwner($membership);
    }
}
