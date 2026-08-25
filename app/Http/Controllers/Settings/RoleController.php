<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Closure;
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
        $role = $request->route('role');

        return $request->validate([
            'name' => ['required', 'string', 'max:80', $this->keyIsFree($role instanceof Role ? $role : null)],
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
     * The derived key must not already be taken — and one of the ways it can
     * be taken is a **security** question rather than a uniqueness one.
     *
     * `Str::slug('Team Owner', '_')` is exactly `team_owner`, the key of the
     * shipped role, and the unique index is over `(team_id, key)` while the
     * shipped rows have no team. So the database permitted it, and every check
     * written as `roles.key = 'team_owner'` then treated the counterfeit as
     * the real thing — including `RevokeMembership`'s last-owner guard, which
     * counted it and would let somebody revoke the only genuine owner and lock
     * a team out of its own settings.
     *
     * `TeamMembership::hasRole()` and `scopeHoldingSystemRole()` now ask for a
     * null `team_id`, which is the half that holds however the row got there.
     * This is the half that keeps the row from being created, and it is worth
     * having both: a refusal a person can read beats a role that silently
     * means nothing.
     *
     * A collision inside the team is the ordinary case, and gets the ordinary
     * message — before this it was a 500 from the unique index.
     */
    private function keyIsFree(?Role $editing): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($editing): void {
            if (! is_string($value)) {
                return;
            }

            $key = Str::slug($value, '_');

            /*
             * A name with no ASCII slugs to the empty string — `Str::slug('🙂',
             * '_') === ''` — and two of those would collide on a key that says
             * nothing. Refused with the reason, rather than by the uniqueness
             * message below, which would claim a role by that name exists when
             * none does.
             */
            if ($key === '') {
                $fail('That name needs some letters or numbers in it — the role’s internal key is derived from it.');

                return;
            }

            if (in_array($key, array_column(SystemRole::cases(), 'value'), true)) {
                $fail('That is the name of a role this product ships. Pick another — a role that shares its name would be indistinguishable from it wherever permissions are checked.');

                return;
            }

            /*
             * **The name is what has to be unique, on both paths.**
             *
             * Two earlier attempts each held one half and let the other
             * through, and the reason is that a rename decouples the key from
             * the name: `update()` deliberately never recomputes the key,
             * because the key is what every permission check and every
             * `membership_role` row is written against and renaming a role
             * must not change what it means.
             *
             * So checking the *key* on create and the *name* on edit — which
             * looked symmetric — still permitted the end state it was written
             * to prevent: create "Deal Lead" (key `deal_lead`), rename it to
             * "Closer" (key stays `deal_lead`), then create "Closer" — whose
             * key `closer` is free, and whose name nothing checked. Two roles
             * called Closer, which is a list nobody can read and a matrix
             * nobody can audit.
             *
             * The name is what a person picks a role by, so the name is the
             * uniqueness that matters and it is asked every time. The key is
             * still checked on create as well, because a name that is *free*
             * can still slug onto a key that is taken — "Deal-Lead" and "Deal
             * Lead" are two names and one key, and the partial unique index
             * would answer that with a 500.
             */
            $teamId = app(TeamContext::class)->requireId(Role::class);

            $sameName = Role::query()
                ->withTrashed()
                ->where('team_id', $teamId)
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                ->when(
                    $editing instanceof Role,
                    fn ($query) => $query->whereKeyNot($editing?->getKey()),
                )
                ->exists();

            if ($sameName) {
                $fail('This team already has a role by that name. Archived ones still count — restore it instead.');

                return;
            }

            if ($editing instanceof Role) {
                // The key is not recomputed by an edit, so there is nothing
                // left to collide.
                return;
            }

            $sameKey = Role::query()
                ->withTrashed()
                ->where('team_id', $teamId)
                ->where('key', $key)
                ->exists();

            if ($sameKey) {
                $fail('That name produces the same internal key as a role this team already has. Pick one that reads differently.');
            }
        };
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
