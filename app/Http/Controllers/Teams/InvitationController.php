<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptInvitation;
use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\Person;
use App\Models\TeamInvitation;
use App\Support\Admin\Impersonation;
use App\Support\Teams\PendingInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accept an invitation (Screen Inventory S04, S09).
 *
 * Deliberately outside the team middleware: the person following this link has
 * no membership yet, and no session. The team comes from the token and from
 * nowhere else.
 *
 * Expired, already accepted, and revoked each get their own state on the
 * screen rather than one generic failure — *"expired and reused tokens each
 * produce their own screen, not a 500"* — because a person who clicks an old
 * link needs to know which of those happened and what to do next.
 *
 * ADR 0003 adds a second door. `show`/`accept` are the emailed link;
 * `claim` is the same acceptance for somebody already signed in as the
 * invited address, who may never have received the message at all. No user
 * flow in this product may depend on email alone, and this is the flow that
 * rule was written for.
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
            'inviterName' => $invitation->invitedBy?->displayNameWithin($invitation->team),
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

        ['person' => $person, 'mayAuthenticate' => $mayAuthenticate] = $accept->handle(
            $invitation,
            $validated['first_name'],
            $validated['last_name'] ?? null,
            $validated['password'],
        );

        if (! $mayAuthenticate) {
            // They already had an account. The link proves possession of an
            // inbox, not of that account's password — so they are on the team
            // now, and they sign in as themselves.
            return to_route('login')->with(
                'status',
                'You’re on the team. Sign in with your existing password and you’ll be there.',
            );
        }

        Auth::login($person);

        $request->session()->regenerate();
        $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $invitation->team_id);

        return to_route('dashboard');
    }

    /**
     * Accept without a link (ADR 0003 · S09).
     *
     * The other half of S04, for the person who never received the email —
     * because no transport is configured, because it went to a spam folder,
     * or because this is a pre-production environment where mail deliberately
     * goes nowhere. `PendingInvitations` sets out why a signed-in account
     * whose address matches is not a weaker proof than the token: the token,
     * for an address that already has an account, does exactly this and
     * nothing more.
     *
     * Not policy-gated, and it should not be. There is no team to hold a
     * policy yet — that is the situation — and the authorisation is the
     * address match, which `PendingInvitations::find()` is the only thing
     * that performs. A miss is a 404 rather than a 403, so a signed-in
     * account cannot walk ids to learn which invitations are live.
     */
    public function claim(Request $request, string $invitation, AcceptInvitation $accept): RedirectResponse
    {
        $person = $request->user();

        /*
         * Not while impersonating.
         *
         * The shell already hides the banner in a support session, and hiding
         * a button whose endpoint still answers is not a control. A support
         * session exists so an administrator can see what the customer sees;
         * joining another team on their behalf is a cross-tenant membership
         * grant, and the audit entry would carry the customer's name.
         */
        $model = $person instanceof Person && ! Impersonation::isActive($request)
            ? PendingInvitations::find($person, $invitation)
            : null;

        if (! $person instanceof Person || ! $model instanceof TeamInvitation) {
            throw new NotFoundHttpException;
        }

        try {
            $accept->claim($model, $person);
        } catch (ValidationException $conflict) {
            /*
             * The directory collision, which `AcceptInvitation` raises under
             * the `email` key. There is no form here — the claim is one
             * button on a banner — so an unrendered validation bag is a click
             * that does nothing at all, forever, with the invitation still
             * sitting there. The sentence is already written for a human, so
             * it goes in a toast.
             */
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $conflict->validator->errors()->first('email'),
            ]);

            return back();
        }

        // Straight into the team they just joined, rather than leaving them
        // to find the switcher: they came here from a screen that exists to
        // say they are not in one.
        $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $model->team_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You’re on the team.')]);

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
