<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open the Horizon dashboard.
     *
     * PRD §4.1: the dashboard sits behind super-admin authorisation. The role
     * itself arrives with tenancy in Slice 1 (epic #2); until then the gate is
     * an explicit allowlist of email addresses from the environment, which is
     * a small, auditable set rather than "any authenticated user".
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            $allowed = array_filter(array_map(
                trim(...),
                explode(',', (string) config('horizon.authorized_emails', '')),
            ));

            return in_array($user->email, $allowed, true);
        });
    }
}
