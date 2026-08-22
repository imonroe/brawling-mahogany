<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DataExport;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class DataExportPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::EXPORT_TEAM_DATA);
    }

    public function view(Person $person, DataExport $export): bool
    {
        return $this->belongsToCurrentTeam($export)
            && $this->allows($person, Permissions::EXPORT_TEAM_DATA);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::EXPORT_TEAM_DATA);
    }

    /**
     * The download is a separate question from the record.
     *
     * PRD §9 wants a signed, expiring link and nothing else — so an export
     * whose window has closed is refused here even for the person who asked
     * for it, rather than relying on the signature having expired.
     */
    public function download(Person $person, DataExport $export): bool
    {
        return $this->view($person, $export) && $export->isDownloadable();
    }
}
