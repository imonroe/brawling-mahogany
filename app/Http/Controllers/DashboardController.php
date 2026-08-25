<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityCategory;
use App\Models\Deal;
use App\Models\Person;
use App\Queries\ActivityFeed;
use App\Queries\TeamDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S10 — the team dashboard (PRD §4.9 F9.1 · Design System §9.3 · #79).
 *
 * The route name is unchanged from the placeholder it replaces, because five
 * things redirect to it — impersonation in and out, the team switcher, and
 * both invitation paths — and a renamed route breaks each of them silently.
 */
class DashboardController extends Controller
{
    public function index(Request $request, ActivityFeed $feed): Response
    {
        /*
         * Gated on the deals the page is made of. Somebody with no
         * `deals.view` sees the empty shell rather than a 403 — they have a
         * team and a sidebar and simply no deals to be shown, which is what
         * the new-team state already looks like.
         */
        /** @var Person $person */
        $person = $request->user();

        $canSeeDeals = $person->can('viewAny', Deal::class);

        return Inertia::render('Dashboard', [
            'canSeeDeals' => $canSeeDeals,
            ...($canSeeDeals ? TeamDashboard::for($person) : self::empty()),
            /*
             * §9.3's activity panel. The team feed's own query object rather
             * than a second one — S12 is the screen this defers to, and two
             * definitions of "recent activity" would disagree within a month.
             */
            'activity' => $canSeeDeals
                ? $feed->rows($feed->query(ActivityCategory::All)->limit(8)->get()->all())
                : [],
        ]);
    }

    /**
     * What the page holds for somebody who cannot see deals.
     *
     * Explicit rather than absent, so the screen renders the same components
     * with nothing in them instead of branching on undefined props — the
     * new-team empty state and the no-permission one are the same picture.
     *
     * @return array<string, mixed>
     */
    private static function empty(): array
    {
        return [
            'stats' => ['activeDeals' => 0, 'blockedStages' => 0, 'overdueTasks' => 0, 'dueSoon' => 0],
            'needsAttention' => [],
            'deals' => [],
            'dueSoon' => [],
        ];
    }
}
