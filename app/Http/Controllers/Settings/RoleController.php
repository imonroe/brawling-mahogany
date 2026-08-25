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
             * **Uniqueness is asked of the key on create and of the name on
             * edit**, because those are the two different things that can
             * collide.
             *
             * `update()` does not recompute the key — the key is what every
             * permission check and every `membership_role` row is written
             * against, so renaming a role must not change what it means. So
             * checking the *key* on an edit refused a rename against a key
             * nothing would write, and reported a role by that name existing
             * when the only match was an old name's slug.
             *
             * Skipping the check entirely was the first correction and was
             * worse: two roles could then both be called "Deal Lead" while
             * keyed `deal_lead` and `closer`, which is a list nobody can read
             * and a permission matrix nobody can audit. The name is what a
             * person picks a role by, so on an edit the name is what has to be
             * unique.
             */
            $teamId = app(TeamContext::class)->requireId(Role::class);

            $taken = Role::query()
                ->withTrashed()
                ->where('team_id', $teamId)
                ->when(
                    $editing instanceof Role,
                    fn ($query) => $query
                        ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                        ->whereKeyNot($editing?->getKey()),
                    fn ($query) => $query->where('key', $key),
                )
                ->exists();

            if ($taken) {
                $fail('This team already has a role by that name. Archived ones still count — restore it instead.');
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
