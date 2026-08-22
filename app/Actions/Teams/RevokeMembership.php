<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\SystemRole;
use App\Models\Person;
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

    /**
     * Unscoped on purpose, and the reason is worth stating.
     *
     * `guardLastOwnerAnywhere()` asks about **every** team a person owns, so
     * the scoped builder made the SQL `team_id = <resolved> AND team_id =
     * <the membership's>` — always zero for any team but the current one, and
     * an exception when no team resolves at all. So an owner of two healthy
     * teams was refused account deletion with a message she could never
     * satisfy, and the owner of a suspended team got a 500.
     */
    private function otherOwnerCount(TeamMembership $membership): int
    {
        return TeamMembership::withoutTeamScope()
            ->where('team_id', $membership->team_id)
            ->whereKeyNot($membership->getKey())
            ->whereNull('revoked_at')
            ->whereHas('roles', fn ($query) => $query->where('roles.key', SystemRole::TeamOwner->value))
            ->whereHas('person', fn ($query) => $query->whereNotNull('password'))
            ->count();
    }

    /**
     * The same rule again, asked before somebody deletes their own account.
     *
     * Revoking the last owner from the members screen was refused; deleting
     * the account went round the back and left the team with nobody who could
     * manage members, settings, or billing — and no way back, since `/admin`
     * provisions teams rather than repairing them.
     *
     * @throws ValidationException
     */
    public function guardLastOwnerAnywhere(Person $person): void
    {
        $memberships = TeamMembership::withoutTeamScope()
            ->where('person_id', $person->getKey())
            ->whereNull('revoked_at')
            ->with('roles')
            ->get();

        foreach ($memberships as $membership) {
            if (! $membership->hasRole(SystemRole::TeamOwner->value)) {
                continue;
            }

            if ($this->otherOwnerCount($membership) > 0) {
                continue;
            }

            throw ValidationException::withMessages([
                'password' => 'You’re the last owner of a team, so deleting your account would leave it with '.
                    'nobody who can manage members, settings, or billing. Make somebody else a Team Owner first.',
            ]);
        }
    }
}
