<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Admin\Impersonation;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Start and end a support session (Screen Inventory S84).
 *
 * The reason is required by the validation *and* by
 * `Impersonation::start()`'s signature, which is deliberate belt and braces:
 * a second call site cannot forget it.
 *
 * The end route is deliberately **not** behind the super-admin middleware. By
 * the time somebody wants out, the authenticated person is the customer, and a
 * guard that checks `is_super_admin` would trap the administrator inside the
 * session they are trying to leave.
 */
class ImpersonationController extends Controller
{
    public function create(string $team, TeamContext $teams): Response
    {
        return $teams->runWithoutScope(function () use ($team): Response {
            $model = Team::query()->findOrFail($team);

            return Inertia::render('Admin/Impersonate', [
                'team' => ['id' => $model->getKey(), 'name' => $model->name],
                /*
                 * The fifth list of "who is on this team", and the one that
                 * used to answer it with "has a password" (#142).
                 *
                 * A password is not access. A Status Viewer who set one — and
                 * Slice 4 gives every client a reason to — was offered here,
                 * and impersonating them landed the operator on `/no-team`,
                 * because `activeTeams()` correctly says they can act in
                 * nothing. Offering somebody to impersonate and then refusing
                 * to be them is a worse answer than not offering them.
                 *
                 * `carryingAccess()` is the same question the members screen,
                 * the People index's Team tab and the console's own member
                 * list now ask. A password is still required on top: an
                 * account that cannot sign in cannot be signed in as.
                 */
                'people' => TeamMembership::withoutTeamScope()
                    ->where('team_id', $model->getKey())
                    ->whereNull('revoked_at')
                    ->carryingAccess()
                    ->whereHas('person', fn ($query) => $query->whereNotNull('password'))
                    ->with('person:id,email,password')
                    ->get()
                    ->map(fn (TeamMembership $membership): array => [
                        'personId' => $membership->person_id,
                        'name' => $membership->fullName(),
                        'email' => $membership->person->email,
                    ])->all(),
                'maxMinutes' => Impersonation::MAX_MINUTES,
            ]);
        });
    }

    public function store(Request $request, string $team, TeamContext $teams): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'string'],
            // A sentence, not a dropdown (S84). Long enough that somebody has
            // to actually say why.
            'reason' => ['required', 'string', 'min:12', 'max:500'],
            'minutes' => ['required', 'integer', 'min:5', 'max:'.Impersonation::MAX_MINUTES],
        ]);

        [$model, $person] = $teams->runWithoutScope(function () use ($team, $validated): array {
            $model = Team::query()->findOrFail($team);

            /*
             * The same question the picker asks, asked again here.
             *
             * Narrowing only `create()` closed the *list* and left the door
             * behind it open: a POST naming a Status Viewer who has a password
             * still started an audited impersonation session and landed the
             * operator on `/no-team`. A picker is a convenience; this is the
             * check.
             */
            $membership = TeamMembership::withoutTeamScope()
                ->where('team_id', $model->getKey())
                ->where('person_id', $validated['person_id'])
                ->whereNull('revoked_at')
                ->carryingAccess()
                ->whereHas('person', fn ($query) => $query->whereNotNull('password'))
                ->with('person')
                ->firstOrFail();

            return [$model, $membership->person];
        });

        Impersonation::start(
            request: $request,
            admin: $request->user(),
            person: $person,
            team: $model,
            reason: $validated['reason'],
            // A form post arrives as a string however the rule reads:
            // `integer` validates, it does not cast.
            minutes: (int) $validated['minutes'],
        );

        $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $model->getKey());

        return to_route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Impersonation::stop($request);

        $person = $request->user();

        if ($person instanceof Person && $person->is_super_admin) {
            return to_route('admin.dashboard');
        }

        return to_route('dashboard');
    }
}
