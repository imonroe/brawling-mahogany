<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Person;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open the Horizon dashboard.
     *
     * PRD §4.1: the dashboard sits behind super-admin authorisation, which
     * Slice 1 made real — `people.is_super_admin`, the same flag `/admin`
     * checks. The environment allowlist survives alongside it for the
     * deployment that needs to open Horizon before anybody is provisioned.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?Person $person): bool {
            if ($person === null) {
                return false;
            }

            if ($person->is_super_admin) {
                return true;
            }

            $allowed = array_filter(array_map(
                trim(...),
                explode(',', (string) config('horizon.authorized_emails', '')),
            ));

            return in_array($person->email, $allowed, true);
        });
    }
}
