<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a support session when its clock runs out (S84, duration).
 *
 * Runs before anything reads the authenticated person, so a request arriving
 * one second after the window closes is served as the administrator rather
 * than as the customer. Both the start and the end are audited; this is the
 * end that nobody clicked.
 */
class HandleImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::hasExpired($request)) {
            Impersonation::stop($request, expired: true);
        }

        return $next($request);
    }
}
