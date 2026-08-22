<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every team-scoped route sits behind this.
 *
 * `ResolveCurrentTeam` establishes the context when there is one to establish.
 * This is the assertion that there was: a signed-in person with no live
 * membership anywhere gets the "no team" screen rather than a page of
 * exceptions from the global scope.
 */
class EnsureTeamContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TeamContext::class)->has()) {
            return redirect()->route('teams.none');
        }

        return $next($request);
    }
}
