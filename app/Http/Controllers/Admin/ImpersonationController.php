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
                'people' => TeamMembership::withoutTeamScope()
                    ->where('team_id', $model->getKey())
                    ->whereNull('revoked_at')
                    ->whereHas('person', fn ($query) => $query->whereNotNull('password'))
                    ->with('person:id,first_name,last_name,email')
                    ->get()
                    ->map(fn (TeamMembership $membership): array => [
                        'personId' => $membership->person_id,
                        'name' => $membership->person->fullName(),
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

            $membership = TeamMembership::withoutTeamScope()
                ->where('team_id', $model->getKey())
                ->where('person_id', $validated['person_id'])
                ->whereNull('revoked_at')
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
