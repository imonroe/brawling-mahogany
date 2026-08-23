<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactImport;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class ContactImportPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::IMPORT_PEOPLE);
    }

    public function view(Person $person, ContactImport $import): bool
    {
        return $this->belongsToCurrentTeam($import)
            && $this->allows($person, Permissions::IMPORT_PEOPLE);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::IMPORT_PEOPLE);
    }

    public function update(Person $person, ContactImport $import): bool
    {
        return $this->view($person, $import);
    }
}
