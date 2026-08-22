<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\Admin\Impersonation;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $person = $request->user();
        $team = app(TeamContext::class)->get();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $person,
                // The permissions this person holds *in this team* — the same
                // question the policies ask, so the navigation hides exactly
                // what the server would refuse (IA §5.1).
                'permissions' => Permissions::grantedTo($person),
                // An impersonated session must be unmistakable: the shell
                // renders a persistent banner whenever this is populated
                // (ADR 0002, PRD §4.1 F1.5).
                'impersonating' => Impersonation::banner($request),
                'isSuperAdmin' => (bool) $person?->is_super_admin,
            ],
            'team' => $this->teamProps($team),
            // S09: the switcher hides itself entirely on a single team, so the
            // list is shared rather than fetched per page.
            'teams' => $person === null ? [] : $person->activeTeams()->map(
                fn (Team $each): array => ['id' => $each->getKey(), 'name' => $each->name],
            )->all(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The resolved team, as the front end needs it.
     *
     * The timezone is the load-bearing field: PRD §9 stores UTC and displays
     * in the team's zone, and `resources/js/lib/formatters.ts` reads this
     * once at boot rather than each screen guessing.
     *
     * @return array<string, mixed>|null
     */
    protected function teamProps(?Team $team): ?array
    {
        if (! $team instanceof Team) {
            return null;
        }

        return [
            'id' => $team->getKey(),
            'name' => $team->name,
            'timezone' => $team->timezone,
            'brandAccentColor' => $team->brand_accent_color,
            'logoPath' => $team->logo_path,
        ];
    }
}
