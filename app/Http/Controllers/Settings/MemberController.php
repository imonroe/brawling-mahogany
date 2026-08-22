<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\InvitePersonToTeam;
use App\Actions\Teams\RevokeMembership;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Teams\InvitationConflict;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members and invitations (Screen Inventory S74).
 *
 * Key states from the inventory: empty, pending invites, revoke, and the one
 * that carries a real rule — **last owner warning**. A team must always keep
 * one Team Owner who can sign in, and RevokeMembership refuses with copy that
 * says why rather than a generic validation message.
 */
class MemberController extends Controller
{
    public function index(TeamContext $teams): Response
    {
        $team = $teams->get();

        $this->authorize('manageMembers', $team);

        return Inertia::render('Settings/Members', [
            'members' => TeamMembership::query()
                ->whereHas('roles', fn ($query) => $query->whereIn('roles.key', ['team_owner', 'team_member']))
                ->with(['person:id,email,password', 'roles:id,key,name'])
                ->get()
                ->map(fn (TeamMembership $membership): array => [
                    'id' => $membership->getKey(),
                    'name' => $membership->fullName(),
                    'email' => $membership->email,
                    'hasLogin' => $membership->person->hasCredentials(),
                    'roles' => $membership->roles->pluck('name')->all(),
                    'revokedAt' => $membership->revoked_at?->toIso8601String(),
                ])->all(),
            'invitations' => TeamInvitation::query()
                ->pending()
                ->with('role:id,name')
                ->get()
                ->map(fn (TeamInvitation $invitation): array => [
                    'id' => $invitation->getKey(),
                    'email' => $invitation->email,
                    'role' => $invitation->role->name,
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                ])->all(),
            'assignableRoles' => Role::query()
                ->assignableWithinTeam($team)
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'description'])
                ->all(),
        ]);
    }

    public function invite(Request $request, TeamContext $teams, InvitePersonToTeam $invite): RedirectResponse
    {
        $team = $teams->get();

        $this->authorize('create', TeamInvitation::class);

        /*
         * Folded before anything compares or stores it.
         *
         * Every other entry point folds — `PersonRules::prepareForValidation`,
         * and the mutators on both models — and this one stored the address
         * verbatim on `team_invitations.email`. Harmless while every lookup
         * happens to use `lower(email)`, which is precisely the kind of
         * harmless that stops being true in one commit.
         */
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                /*
                 * Refused here, where the person who can resolve it is
                 * standing. Checking only at accept time meant the invitation
                 * sent cleanly and the *invitee* met a validation error on a
                 * screen with no field to show it — silent for everybody.
                 */
                function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                    $reason = is_string($value)
                        ? InvitationConflict::reasonFor($team->getKey(), $value)
                        : null;

                    if ($reason !== null) {
                        $fail($reason);
                    }
                },
            ],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'role_id' => [
                'required',
                'string',
                // Scoped rather than a bare `exists`: a foreign role id in a
                // form submission is exactly the vector the isolation suite
                // enumerates, and it has to fail validation, not write.
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where(fn ($inner) => $inner
                        ->whereNull('team_id')
                        ->orWhere('team_id', $team->getKey()))
                        ->where('key', '!=', 'super_administrator')
                        // A role the team has retired is not a role somebody
                        // can be invited into. `Rule::exists` reads the table
                        // directly, so the soft-delete scope is not applied
                        // for us here.
                        ->whereNull('deleted_at'),
                ),
            ],
        ]);

        $invite->handle(
            team: $team,
            email: $validated['email'],
            role: Role::query()->whereKey($validated['role_id'])->sole(),
            invitedBy: $request->user(),
            firstName: $validated['first_name'] ?? null,
            lastName: $validated['last_name'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('members.index');
    }

    public function revokeInvitation(TeamInvitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->forceFill(['revoked_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return to_route('members.index');
    }

    public function revoke(TeamMembership $membership, RevokeMembership $revoke): RedirectResponse
    {
        $this->authorize('manageAccess', $membership);

        $revoke->handle($membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access revoked.')]);

        return to_route('members.index');
    }
}
