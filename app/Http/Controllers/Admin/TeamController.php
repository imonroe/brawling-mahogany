<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\InvitePersonToTeam;
use App\Actions\Teams\IssueInvitationLink;
use App\Actions\Teams\ProvisionTeam;
use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teams list, detail, and provisioning (Screen Inventory S82, S83).
 *
 * PRD §5.1 step 1: *"Ian provisions a team and invites the owner."* This is
 * where a customer's life in the product begins, and where Slice 1's exit
 * criterion starts.
 *
 * PRD §9 requires the audit trail to prove that cross-tenant access was
 * *appropriate*, which means recording the access and not only the writes —
 * so opening a team's detail page writes an entry too.
 */
class TeamController extends Controller
{
    public function index(Request $request, TeamContext $teams): Response
    {
        $search = trim((string) $request->query('search', ''));

        return $teams->runWithoutScope(function () use ($search): Response {
            $query = Team::query()->withCount('memberships');

            if ($search !== '') {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($inner) => $inner
                    ->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(slug) like ?', [$term]));
            }

            return Inertia::render('Admin/Teams/Index', [
                'search' => $search,
                'teams' => $query->orderBy('name')->paginate(25)->withQueryString()->through(
                    fn (Team $team): array => [
                        'id' => $team->getKey(),
                        'name' => $team->name,
                        'slug' => $team->slug,
                        'memberCount' => $team->memberships_count,
                        'suspendedAt' => $team->suspended_at?->toIso8601String(),
                        'purgeAfter' => $team->purge_after?->toIso8601String(),
                        'createdAt' => $team->created_at?->toIso8601String(),
                    ],
                ),
            ]);
        });
    }

    public function show(string $team, TeamContext $teams, AuditLogger $audit): Response
    {
        return $teams->runWithoutScope(function () use ($team, $audit): Response {
            $model = Team::query()->findOrFail($team);

            // PRD §9: the trail has to prove access was appropriate, which
            // means recording that it happened.
            $audit->record(action: 'admin.team_viewed', auditable: $model, teamId: $model->getKey());

            return Inertia::render('Admin/Teams/Show', [
                'team' => [
                    'id' => $model->getKey(),
                    'name' => $model->name,
                    'slug' => $model->slug,
                    'timezone' => $model->timezone,
                    'suspendedAt' => $model->suspended_at?->toIso8601String(),
                    'purgeAfter' => $model->purge_after?->toIso8601String(),
                    'createdAt' => $model->created_at?->toIso8601String(),
                ],
                'usage' => [
                    'members' => TeamMembership::withoutTeamScope()->where('team_id', $model->getKey())->count(),
                    'activeMembers' => TeamMembership::withoutTeamScope()
                        ->where('team_id', $model->getKey())
                        ->whereNull('revoked_at')
                        ->count(),
                ],
                /*
                 * The invitations this team is still waiting on (ADR 0003).
                 *
                 * The console's first act is to provision a team *and invite
                 * its owner*, and until this list existed the only record of
                 * that invitation was a message the operator could not see,
                 * resend, or replace. On an install with no mail transport —
                 * every fresh local environment, and staging by design — the
                 * product had no first user and no screen that admitted it.
                 */
                'invitations' => TeamInvitation::withoutTeamScope()
                    ->where('team_id', $model->getKey())
                    ->pending()
                    ->with('role:id,name')
                    ->get()
                    ->map(fn (TeamInvitation $invitation): array => [
                        'id' => $invitation->getKey(),
                        'email' => $invitation->email,
                        'role' => $invitation->role->name,
                        'expiresAt' => $invitation->expires_at->toIso8601String(),
                    ])->all(),
                // Shown once, then gone: only the hash is stored, so there is
                // nothing to read back on a later visit.
                'issuedLink' => session('invitationLink'),
                'members' => TeamMembership::withoutTeamScope()
                    ->where('team_id', $model->getKey())
                    ->whereHas('roles', fn ($query) => $query->whereIn('roles.key', [
                        SystemRole::TeamOwner->value,
                        SystemRole::TeamMember->value,
                    ]))
                    ->with(['person:id,email,password', 'roles:id,key,name'])
                    ->get()
                    ->map(fn (TeamMembership $membership): array => [
                        'id' => $membership->getKey(),
                        'name' => $membership->fullName(),
                        'email' => $membership->person->email,
                        'roles' => $membership->roles->pluck('name')->all(),
                        'revokedAt' => $membership->revoked_at?->toIso8601String(),
                    ])->all(),
            ]);
        });
    }

    public function store(Request $request, ProvisionTeam $provision, InvitePersonToTeam $invite, TeamContext $teams): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $team = $teams->runWithoutScope(fn (): Team => $provision->handle([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
        ]));

        // Step 1 of onboarding is provisioning *and* inviting the owner; a
        // team with nobody who can sign in is a team that never starts.
        $teams->runFor($team, fn () => $invite->handle(
            team: $team,
            email: $validated['owner_email'],
            role: Role::query()->whereNull('team_id')->where('key', SystemRole::TeamOwner->value)->sole(),
            invitedBy: $request->user(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team provisioned and the owner invited.')]);

        return to_route('admin.teams.show', ['team' => $team->getKey()]);
    }

    /**
     * The accept link for an invitation this console sent (ADR 0003).
     *
     * PRD §5.1 step 1 is *"Ian provisions a team and invites the owner"*, and
     * step 2 was silently *"and hopes the mail landed"*. A platform operator
     * already holds every privilege over every team; handing them a link to
     * an invitation they caused adds nothing, and it is what makes the first
     * customer reachable on an install where mail goes nowhere.
     *
     * Audited like everything else the console does — `IssueInvitationLink`
     * writes the entry against the team, with this administrator as actor.
     */
    public function issueInvitationLink(
        Request $request,
        string $team,
        string $invitation,
        TeamContext $teams,
        IssueInvitationLink $issue,
    ): RedirectResponse {
        $link = $teams->runWithoutScope(function () use ($team, $invitation, $request, $issue): ?array {
            $model = TeamInvitation::withoutTeamScope()
                ->where('team_id', $team)
                ->pending()
                ->whereKey($invitation)
                ->first();

            if (! $model instanceof TeamInvitation) {
                return null;
            }

            return [
                'id' => $model->getKey(),
                'email' => $model->email,
                'url' => $issue->handle($model, $request->user()),
            ];
        });

        if ($link === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That invitation is no longer live.')]);

            return to_route('admin.teams.show', ['team' => $team]);
        }

        return to_route('admin.teams.show', ['team' => $team])->with('invitationLink', $link);
    }

    public function suspend(string $team, TeamContext $teams, AuditLogger $audit): RedirectResponse
    {
        return $this->setSuspension($team, $teams, $audit, suspended: true);
    }

    public function restore(string $team, TeamContext $teams, AuditLogger $audit): RedirectResponse
    {
        return $this->setSuspension($team, $teams, $audit, suspended: false);
    }

    private function setSuspension(string $team, TeamContext $teams, AuditLogger $audit, bool $suspended): RedirectResponse
    {
        $teams->runWithoutScope(function () use ($team, $audit, $suspended): void {
            $model = Team::query()->findOrFail($team);

            $model->forceFill(['suspended_at' => $suspended ? now() : null])->save();

            $audit->record(
                action: $suspended ? 'admin.team_suspended' : 'admin.team_restored',
                auditable: $model,
                teamId: $model->getKey(),
            );
        });

        return to_route('admin.teams.show', ['team' => $team]);
    }
}
