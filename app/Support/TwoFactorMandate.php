<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SystemRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\TeamMembership;

/**
 * Who must hold two-factor authentication, and whether they do (PRD §9).
 *
 * A Team Owner in *any* team is caught, not only in the one they happen to be
 * looking at: the mandate follows the role, and a person who owns a team is a
 * person whose account protects that team wherever they are standing.
 */
final class TwoFactorMandate
{
    public function applies(Person $person): bool
    {
        return $this->isMandatoryFor($person) && ! $this->isEnrolled($person);
    }

    public function isMandatoryFor(Person $person): bool
    {
        if ($person->is_super_admin) {
            return true;
        }

        $mandatoryRoleIds = Role::query()
            ->whereNull('team_id')
            ->whereIn('key', $this->mandatoryRoleKeys())
            ->pluck('id');

        if ($mandatoryRoleIds->isEmpty()) {
            return false;
        }

        return TeamMembership::withoutTeamScope()
            ->where('person_id', $person->getKey())
            ->whereNull('revoked_at')
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $mandatoryRoleIds))
            ->exists();
    }

    /**
     * Confirmed, not merely started.
     *
     * A half-finished enrolment leaves `two_factor_secret` set and
     * `two_factor_confirmed_at` null, and treating that as protection would
     * let somebody scan a QR code, close the tab, and be waved through.
     */
    public function isEnrolled(Person $person): bool
    {
        return $person->two_factor_secret !== null && $person->two_factor_confirmed_at !== null;
    }

    /**
     * @return list<string>
     */
    private function mandatoryRoleKeys(): array
    {
        return array_values(array_map(
            fn (SystemRole $role): string => $role->value,
            array_filter(SystemRole::cases(), fn (SystemRole $role): bool => $role->requiresTwoFactor()),
        ));
    }
}
