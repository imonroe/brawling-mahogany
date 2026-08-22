<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforcement layer 3 (ADR 0002): resolve the team, reject a mismatch.
 *
 * **Session only, so far.** A signed-in person carries a current team in the
 * session, set at sign-in and changed only through the team switcher (S09).
 * That is the whole of it today, because no tenant route names a team: every
 * one of them binds a team-scoped model instead and lets the global scope do
 * the isolation. `/admin` names teams and sits outside this middleware.
 *
 * ADR 0002's *"session first, route second, and they must agree"* is the rule
 * for the first tenant route that does name one — rejecting rather than
 * reconciling, because silently switching somebody's team on a link click is
 * how people act in the wrong context. There is nothing here implementing it
 * yet, and this docblock said there was.
 *
 * A team the person has no live membership in is a **404**, never a 403: a 403
 * confirms the record exists, and that is itself a disclosure.
 */
class ResolveCurrentTeam
{
    public const SESSION_KEY = 'current_team_id';

    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->user();

        if ($person === null) {
            return $next($request);
        }

        $team = $this->resolve($request);

        if ($team === null) {
            // Signed in with no team: the switcher's "no access" state
            // (S09). Everything team-scoped will refuse, which is correct.
            return $next($request);
        }

        app(TeamContext::class)->set($team);

        $request->session()->put(self::SESSION_KEY, $team->getKey());

        return $next($request);
    }

    /**
     * The team this request acts in, or null when the person has none.
     */
    protected function resolve(Request $request): ?Team
    {
        $person = $request->user();

        if ($person === null) {
            return null;
        }

        $teams = $person->activeTeams();

        if ($teams->isEmpty()) {
            return null;
        }

        $remembered = $request->session()->get(self::SESSION_KEY);

        $team = $teams->firstWhere('id', $remembered);

        // A remembered team the person has since been revoked from, or a
        // suspended one, falls back to whatever they still have rather than
        // stranding them on a dead session value.
        return $team ?? $teams->first();
    }
}
