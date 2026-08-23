<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Property;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Properties (S35, S36, S37 · issue #61).
 *
 * Viewing and changing are separate permissions for the same reason they are
 * on deals: PRD §4.2 F2.2's Read Only role exists for a bookkeeper or a broker
 * who needs to see what the team is working on and must not be able to edit
 * it.
 */
class PropertyPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_PROPERTIES);
    }

    public function view(Person $person, Property $property): bool
    {
        return $this->belongsToCurrentTeam($property)
            && $this->allows($person, Permissions::VIEW_PROPERTIES);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_PROPERTIES);
    }

    public function update(Person $person, Property $property): bool
    {
        return $this->belongsToCurrentTeam($property)
            && $this->allows($person, Permissions::MANAGE_PROPERTIES);
    }

    public function delete(Person $person, Property $property): bool
    {
        return $this->update($person, $property);
    }

    /**
     * Putting a property on a deal, or taking it off.
     *
     * Both permissions, deliberately. The row says something about the deal —
     * which house it is about, and therefore what it is called — so somebody
     * who may edit properties but not deals should not be able to rename one
     * from the property screen. `MANAGE_DEALS` alone would be the mirror
     * mistake.
     */
    public function link(Person $person, Property $property): bool
    {
        return $this->belongsToCurrentTeam($property)
            && $this->allows($person, Permissions::MANAGE_PROPERTIES)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }
}
