<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Admin\Impersonation;
use App\Support\Permissions;
use App\Support\Teams\PendingInvitations;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                'user' => $this->personProps($person, $team),
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
            /*
             * Invitations waiting for whoever is signed in (ADR 0003 · S09).
             *
             * Shared rather than fetched per screen, for the same reason the
             * team list is: the shell renders a banner from it, and a person
             * who has just been invited is by definition somebody who does
             * not know where to go looking. One indexed lookup on a folded
             * address — see `team_invitations_pending_email` — and empty for
             * the overwhelming majority of requests.
             *
             * **Never during impersonation.** A support session acts with the
             * customer's permissions so that an administrator can see what
             * they see; joining another team on their behalf is not seeing,
             * it is a cross-tenant membership grant in one click, and
             * `finalise()` would sign the audit entry with the customer's
             * name rather than the administrator's.
             */
            'invitations' => Impersonation::isActive($request)
                ? []
                : PendingInvitations::propsFor($person),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The signed-in person, as the front end needs them.
     *
     * Shaped rather than serialised, because since #140 the name is not on the
     * `people` row — it is on the membership for the team they are standing
     * in. Handing the model over would send a login record to a shell that
     * wants a name and initials.
     *
     * A person with no team has no name yet: they have registered, or their
     * access was revoked, and the switcher's "no access" state is what they
     * see. The address is theirs, so it stands in.
     *
     * @return array<string, mixed>|null
     */
    private function personProps(?Person $person, ?Team $team): ?array
    {
        if (! $person instanceof Person) {
            return null;
        }

        $membership = $team instanceof Team ? $person->membershipIn($team) : null;

        return [
            'id' => $person->getKey(),
            'email' => $person->email,
            'first_name' => $membership instanceof TeamMembership
                ? $membership->first_name
                : Str::before((string) $person->email, '@'),
            'last_name' => $membership?->last_name,
            'email_verified_at' => $person->email_verified_at?->toIso8601String(),
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
