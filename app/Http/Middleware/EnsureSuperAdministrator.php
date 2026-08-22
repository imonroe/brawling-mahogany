<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/admin` namespace, for the platform operator only (PRD §4.1 F1.5).
 *
 * Issue #52: *"A non-super-admin gets a 404 (not a 403 — do not confirm the
 * namespace exists) on every `/admin` route."* A 403 tells somebody probing
 * that there is a super admin console at this address, which is the one thing
 * the response should not say.
 *
 * Impersonation deliberately does **not** pass: an impersonating admin holds
 * the impersonated person's permissions, never their own (ADR 0002), so the
 * console is closed for the duration of the session.
 */
class EnsureSuperAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->user();

        if ($person === null || ! $person->is_super_admin || Impersonation::isActive($request)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
