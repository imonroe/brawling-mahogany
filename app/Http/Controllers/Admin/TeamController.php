<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\InvitePersonToTeam;
use App\Actions\Teams\ProvisionTeam;
use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
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
                'members' => TeamMembership::withoutTeamScope()
                    ->where('team_id', $model->getKey())
                    ->whereHas('roles', fn ($query) => $query->whereIn('roles.key', [
                        SystemRole::TeamOwner->value,
                        SystemRole::TeamMember->value,
                    ]))
                    ->with(['person:id,first_name,last_name,email', 'roles:id,key,name'])
                    ->get()
                    ->map(fn (TeamMembership $membership): array => [
                        'id' => $membership->getKey(),
                        'name' => $membership->person->fullName(),
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
