<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\InvitePersonToTeam;
use App\Actions\Teams\RevokeMembership;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
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
                ->with(['person:id,first_name,last_name,email,password', 'roles:id,key,name'])
                ->get()
                ->map(fn (TeamMembership $membership): array => [
                    'id' => $membership->getKey(),
                    'name' => $membership->person->fullName(),
                    'email' => $membership->person->email,
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

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
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
                        ->where('key', '!=', 'super_administrator'),
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
