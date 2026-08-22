<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptInvitation;
use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accept an invitation (Screen Inventory S04).
 *
 * Deliberately outside the team middleware: the person following this link has
 * no membership yet, and no session. The team comes from the token and from
 * nowhere else.
 *
 * Expired, already accepted, and revoked each get their own state on the
 * screen rather than one generic failure — *"expired and reused tokens each
 * produce their own screen, not a 500"* — because a person who clicks an old
 * link needs to know which of those happened and what to do next.
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response
    {
        $invitation = $this->find($token);

        $state = match (true) {
            $invitation->isRevoked() => 'revoked',
            $invitation->isAccepted() => 'accepted',
            $invitation->isExpired() => 'expired',
            default => 'pending',
        };

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'state' => $state,
            'email' => $invitation->email,
            'firstName' => $invitation->first_name,
            'lastName' => $invitation->last_name,
            'teamName' => $invitation->team()->sole()->name,
            'inviterName' => $invitation->invitedBy?->fullName(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        $invitation = $this->find($token);

        if (! $invitation->isPending()) {
            // Not a validation error: the link itself is the problem, and the
            // screen already knows how to say so.
            return to_route('invitations.show', ['token' => $token]);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $person = $accept->handle(
            $invitation,
            $validated['first_name'],
            $validated['last_name'] ?? null,
            $validated['password'],
        );

        Auth::login($person);

        $request->session()->regenerate();
        $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $invitation->team_id);

        return to_route('dashboard');
    }

    /**
     * Resolve a token to its invitation.
     *
     * Unscoped on purpose: this route has no team context by definition, and
     * the token is what establishes one. A token that matches nothing is a
     * 404 — never a message distinguishing "no such invitation" from "not
     * yours", which would let somebody probe for live tokens.
     */
    private function find(string $token): TeamInvitation
    {
        $invitation = TeamInvitation::withoutTeamScope()
            ->where('token_hash', TeamInvitation::hashToken($token))
            ->with(['invitedBy'])
            ->first();

        if (! $invitation instanceof TeamInvitation) {
            throw new NotFoundHttpException;
        }

        return $invitation;
    }
}
