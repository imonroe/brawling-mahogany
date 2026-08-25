<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Who may read and change an uploaded file (PRD §4.6, §9 · issue #63).
 *
 * Built on `properties.*` rather than a `documents.*` key, for the reason
 * `TaskPolicy` is built on `deals.*`: this slice's only documents are a
 * property's photographs, and inventing a permission no shipped role holds
 * would put a key in the catalogue that nothing grants. Slice 3's document
 * module brings its own — F6.5's restricted categories need
 * `VIEW_RESTRICTED_DOCUMENT`, which is already in the catalogue waiting.
 */
class DocumentPolicy
{
    use ChecksTeamPermissions;

    public function view(Person $person, Document $document): bool
    {
        return $this->belongsToCurrentTeam($document)
            && $this->allows($person, Permissions::VIEW_PROPERTIES);
    }

    public function update(Person $person, Document $document): bool
    {
        return $this->belongsToCurrentTeam($document)
            && $this->allows($person, Permissions::MANAGE_PROPERTIES);
    }

    public function delete(Person $person, Document $document): bool
    {
        return $this->update($person, $document);
    }
}
