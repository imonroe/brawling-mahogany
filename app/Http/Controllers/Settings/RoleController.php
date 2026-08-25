<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S75 — roles and permissions (PRD §4.2 F2.3 · IA §7 · issue #88).
 *
 * ## Composed, never edited
 *
 * F2.3 is that a team differs by **composing a new role**, and `RolePolicy`
 * refuses to update a system one. The five shipped roles are the product's,
 * not the customer's: a team that edited Team Member would change what that
 * name means for everybody reading their own audit log six months later.
 * So system rows get no controls at all rather than disabled ones — the rule
 * Frontend conventions §4 records for deal types, and this is its second
 * screen.
 *
 * ## A lookup is archived, never deleted
 *
 * The same pattern S76 set, and for the same reasons: there is **no destroy
 * route**, the in-use count is shown *before* the choice rather than reported
 * after it, archiving is reversible, and the count is scoped to the asking
 * team. A role held by four people is a role whose removal takes four people's
 * access with it, and that is a sentence somebody should read first.
 *
 * ## Only the team surface
 *
 * The permission list offered here is `PermissionSurface::Team`. Handing a
 * team the platform console's permissions would be a customer granting
 * themselves `/admin`, and the client surface belongs to #110's status page —
 * neither is a team's to compose from.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $teamId = app(TeamContext::class)->requireId(Role::class);

        $roles = Role::query()
            ->withTrashed()
            ->where(fn ($query) => $query->whereNull('team_id')->orWhere('team_id', $teamId))
            ->with('permissions')
            ->withCount(['memberships' => fn ($query) => $query->whereNull('revoked_at')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Roles', [
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'description' => $role->description,
                'isSystem' => $role->is_system,
                'isArchived' => $role->trashed(),
                /*
                 * The count **before** the choice, not after it. A role held
                 * by four people is a role whose archiving takes four people's
                 * access with it.
                 */
                'holders' => (int) ($role->getAttribute('memberships_count') ?? 0),
                'permissions' => $role->permissions->pluck('key')->all(),
            ])->values()->all(),
            /*
             * The catalogue, grouped as it is described. Team surface only —
             * a team composing from the platform console's permissions is a
             * customer granting themselves `/admin`.
             */
            'catalogue' => collect(Permissions::catalogue())
                ->filter(fn (array $entry): bool => $entry['surface'] === \App\Enums\PermissionSurface::Team)
                ->map(fn (array $entry, string $key): array => [
                    'key' => $key,
                    'group' => $entry['group'],
                    'description' => $entry['description'],
                ])
                ->values()
                ->all(),
            'can' => [
                'manage' => $request->user()?->can('create', Role::class) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $validated = $this->validated($request);

        $teamId = app(TeamContext::class)->requireId(Role::class);

        $role = new Role;

        $role->forceFill([
            'team_id' => $teamId,
            /*
             * The key is derived and never typed. It is what
             * `membership_role` and every permission check are written
             * against, and a customer choosing `team_owner` for a role holding
             * one permission would be a customer choosing what a name means
             * in this product.
             */
            'key' => Str::slug($validated['name'], '_'),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ])->save();

        $this->syncPermissions($role, $validated['permissions'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return back(fallback: route('roles.index'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $validated = $this->validated($request);

        $role->forceFill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ])->save();

        $this->syncPermissions($role, $validated['permissions'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role saved.')]);

        return back(fallback: route('roles.index'));
    }

    /**
     * Archive, and un-archive. **There is no destroy route at all.**
     *
     * S76 set this pattern and the reasoning is the same: a role appears in
     * audit entries and in every membership that ever held it, and a hard
     * delete makes those unreadable. Reversible, because the reason somebody
     * archives the wrong one is that they misread the count.
     */
    public function archive(Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $role->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Role archived. Anybody who held it keeps their history.'),
        ]);

        return back(fallback: route('roles.index'));
    }

    public function restore(string $role): RedirectResponse
    {
        $found = Role::withTrashed()->findOrFail($role);

        $this->authorize('update', $found);

        $found->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role restored.')]);

        return back(fallback: route('roles.index'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['array'],
            /*
             * Team surface only, and validated rather than filtered: a request
             * naming `platform.teams.manage` is a request nobody's screen
             * rendered, and answering it quietly by dropping the key would
             * hide an attempt worth refusing.
             */
            'permissions.*' => ['string', 'in:'.implode(',', Permissions::teamSurfaceKeys())],
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function syncPermissions(Role $role, array $keys): void
    {
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $keys)->pluck('id')->all(),
        );
    }
}
