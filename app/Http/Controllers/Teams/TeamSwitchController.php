<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The team switcher (Screen Inventory S09).
 *
 * *"Context switching must change the resolved team for every subsequent
 * query, including anything queued from that request."* It does, because the
 * session value is the only input `ResolveCurrentTeam` reads, and a job
 * dispatched later captures the team it was dispatched in (RunsForTeam).
 *
 * A team the person has no live membership in is a 404 rather than a 403:
 * confirming that a team exists is itself a disclosure.
 */
class TeamSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['team' => ['required', 'string']]);

        $team = $request->user()->activeTeams()->firstWhere('id', $validated['team']);

        if ($team === null) {
            throw new NotFoundHttpException;
        }

        $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $team->getKey());

        return to_route('dashboard');
    }
}
