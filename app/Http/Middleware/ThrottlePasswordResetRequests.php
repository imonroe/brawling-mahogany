<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The throttle Fortify's `POST /forgot-password` route does not carry.
 *
 * Issue #43 calls this *"the one people forget"*. It is applied here, in the
 * web stack, rather than by reaching into Fortify's route after the fact:
 * that depends on when the package's routes happen to be registered, and a
 * rate limit that silently stops applying is worse than none.
 *
 * Laravel's password broker already throttles a repeat request for the *same*
 * address. The `password-reset` limiter is keyed by origin, which is the case
 * the broker does not cover: somebody walking a list of addresses to find out
 * which ones exist.
 */
class ThrottlePasswordResetRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('forgot-password')) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, 'password-reset');
    }
}
