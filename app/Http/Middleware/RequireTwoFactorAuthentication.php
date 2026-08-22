<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TwoFactorMandate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD §9: *"2FA available, **mandatory for Team Owner and Super
 * Administrator**."*
 *
 * That word does the work. It is not a setting those roles are encouraged to
 * turn on — a Team Owner without 2FA cannot reach the application beyond the
 * enrolment screen.
 *
 * The redirect target is the security page rather than a bespoke wall, so the
 * person lands somewhere that can actually finish the job, with an explanation
 * (S77).
 */
class RequireTwoFactorAuthentication
{
    /**
     * Routes the person must still be able to reach: the enrolment screen
     * itself, its endpoints, and the way out.
     */
    private const ALLOWED = [
        'settings/*',
        'user/two-factor-authentication*',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-qr-code',
        'user/two-factor-secret-key',
        'user/two-factor-recovery-codes',
        'user/confirm-password',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->user();

        if ($person === null || ! app(TwoFactorMandate::class)->applies($person)) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED)) {
            return $next($request);
        }

        return redirect()
            ->route('security.edit')
            ->with('status', 'two-factor-required');
    }
}
