<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super admin dashboard (Screen Inventory S81).
 *
 * IA §5.5: `/admin` is a separate route namespace, visually distinct from the
 * tenant app so nobody ever confuses the two. `AdminLayout` carries that.
 *
 * Everything here reads across tenants, which is the whole point and the whole
 * risk — so every read runs through the explicit `runWithoutScope()` bypass
 * rather than through an absent scope, and the *counts* stay counts: this
 * screen never renders a customer's records.
 */
class DashboardController extends Controller
{
    public function __invoke(TeamContext $teams): Response
    {
        return $teams->runWithoutScope(fn (): Response => Inertia::render('Admin/Dashboard', [
            'teamCount' => Team::query()->count(),
            'suspendedCount' => Team::query()->whereNotNull('suspended_at')->count(),
            'personCount' => Person::query()->count(),
            'membershipCount' => TeamMembership::query()->count(),
            'recentAudit' => AuditEntry::query()
                ->latest('created_at')
                ->limit(15)
                ->get()
                ->map(fn (AuditEntry $entry): array => [
                    'id' => $entry->getKey(),
                    'action' => $entry->action,
                    'teamId' => $entry->team_id,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])->all(),
        ]));
    }
}
