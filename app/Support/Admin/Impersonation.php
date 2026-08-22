<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Person;
use App\Models\Team;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Super-admin impersonation (PRD §4.1 F1.5, §9 Audit · Screen Inventory S84).
 *
 * The controls that make this acceptable rather than alarming, all of them
 * enforced here rather than asked for politely:
 *
 *  - **A typed reason**, stored verbatim on the audit entry. Not a dropdown.
 *  - **A duration**, after which the session reverts on the next request.
 *  - **An unmissable banner** for the whole session — the shell renders it
 *    whenever `auth.impersonating` is present.
 *  - **An audit entry on start and on end.**
 *  - **The impersonated person's permissions, never the admin's** (ADR 0002).
 *    A support session must not be able to do what the customer cannot.
 *
 * The session keys live under one prefix so ending an impersonation is one
 * `forget()` and cannot half-succeed.
 */
final class Impersonation
{
    private const KEY = 'impersonation';

    /** S84's duration control. Long enough to help, short enough to expire on its own. */
    public const MAX_MINUTES = 60;

    public static function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY.'.admin_person_id');
    }

    /**
     * The banner's payload, or null when nobody is impersonating.
     *
     * @return array{name: string, teamName: string, reason: string, endsAt: string}|null
     */
    public static function banner(Request $request): ?array
    {
        if (! self::isActive($request)) {
            return null;
        }

        $session = $request->session();

        return [
            'name' => (string) $session->get(self::KEY.'.person_name'),
            'teamName' => (string) $session->get(self::KEY.'.team_name'),
            'reason' => (string) $session->get(self::KEY.'.reason'),
            'endsAt' => (string) $session->get(self::KEY.'.expires_at'),
        ];
    }

    /**
     * Begin acting as somebody, and say why.
     *
     * The reason is required by the signature, not by a validation rule that
     * could be forgotten at a second call site.
     */
    public static function start(Request $request, Person $admin, Person $person, Team $team, string $reason, int $minutes): void
    {
        $expiresAt = Carbon::now()->addMinutes(min($minutes, self::MAX_MINUTES));

        app(AuditLogger::class)->record(
            action: 'impersonation.started',
            auditable: $person,
            teamId: $team->getKey(),
            actorPersonId: $admin->getKey(),
            reason: $reason,
            after: ['team_id' => $team->getKey(), 'expires_at' => $expiresAt->toIso8601String()],
        );

        Auth::login($person);

        // A fresh session id, so the admin's session cannot be replayed into
        // the impersonated one or the other way round.
        $request->session()->regenerate();

        $request->session()->put(self::KEY, [
            'admin_person_id' => $admin->getKey(),
            'person_id' => $person->getKey(),
            'person_name' => $person->fullName(),
            'team_id' => $team->getKey(),
            'team_name' => $team->name,
            'reason' => $reason,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Hand the session back to the administrator.
     *
     * `$expired` distinguishes the two ways this happens, because "the admin
     * finished" and "the clock ran out" are different facts about a support
     * session and the audit trail should say which.
     */
    public static function stop(Request $request, bool $expired = false): void
    {
        if (! self::isActive($request)) {
            return;
        }

        $session = $request->session();
        $adminId = (string) $session->get(self::KEY.'.admin_person_id');
        $personId = (string) $session->get(self::KEY.'.person_id');
        $teamId = (string) $session->get(self::KEY.'.team_id');
        $reason = (string) $session->get(self::KEY.'.reason');

        $session->forget(self::KEY);

        $admin = Person::query()->find($adminId);

        app(AuditLogger::class)->record(
            action: $expired ? 'impersonation.expired' : 'impersonation.ended',
            auditableType: Person::class,
            auditableId: $personId,
            teamId: $teamId,
            actorPersonId: $adminId,
            reason: $reason,
        );

        if ($admin instanceof Person) {
            Auth::login($admin);
        } else {
            Auth::logout();
        }

        $session->regenerate();
    }

    public static function hasExpired(Request $request): bool
    {
        if (! self::isActive($request)) {
            return false;
        }

        $expiresAt = $request->session()->get(self::KEY.'.expires_at');

        return is_string($expiresAt) && Carbon::parse($expiresAt)->isPast();
    }

    public static function teamId(Request $request): ?string
    {
        $id = $request->session()->get(self::KEY.'.team_id');

        return is_string($id) ? $id : null;
    }
}
