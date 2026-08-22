<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\DealSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DealTypeRules;
use App\Http\Requests\Settings\StoreDealTypeRequest;
use App\Http\Requests\Settings\UpdateDealTypeRequest;
use App\Models\Deal;
use App\Models\DealType;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deal types (Screen Inventory S76 · PRD §4.3 F3.1, §7.6 · issue #58).
 *
 * The three key states the inventory asks for are **defaults**, **custom**,
 * and the **in-use warning** — and the third is the one that carries a rule.
 *
 * ## Archive, never delete
 *
 * This is the pattern for every lookup screen in the product, and the reason
 * is that a lookup is pointed at. Deleting "Rental Placement" would orphan
 * every rental deal that ever used it — the deal would render with a blank
 * type, and the type is what decides which workflow templates are offered and
 * whether the Offers tab exists at all (IA §5.2).
 *
 * So there is no destroy action here, deliberately. The table does carry a
 * `deleted_at` — `HasProductDefaults` brings `SoftDeletes` — but nothing in
 * the product ever sets it: there is no destroy route, and `records:purge`
 * discovers its tables through `BelongsToTeam`, which this model does not use.
 * Archiving keeps existing deals labelled
 * and takes the type out of every picker, which is what somebody actually
 * means when they try to delete one. The warning says how many deals it would
 * affect *before* the choice, rather than reporting the consequence after.
 *
 * ## The system defaults are read-only, on purpose
 *
 * Seller Representation, Buyer Representation and Rental Placement are rows
 * with a null `team_id`, shared by every team on the platform. One team
 * renaming or hiding "Rental Placement" for everybody is not what they asked
 * for — so the policy refuses, and the screen shows them without controls
 * rather than with disabled ones (IA §5.1). Taking a system type out of *one*
 * team's picker is a real want and a different feature.
 */
class DealTypeController extends Controller
{
    public function index(Request $request, TeamContext $teams): Response
    {
        $this->authorize('viewAny', DealType::class);

        $team = $teams->get();

        /*
         * Both kinds in one list, ordered the way the picker will order them:
         * system defaults first, then the team's own, each by `sort_order`.
         * A screen that separated them into two cards would make "where does
         * mine appear relative to the defaults" unanswerable, and that is
         * exactly what `sort_order` decides.
         */
        $types = DealType::query()
            ->visibleTo($team)
            ->orderByRaw('team_id is not null')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        /*
         * One grouped count for the whole page, not one per row.
         *
         * `PeopleIndexBudgetTest` sets the house standard — *"the same page,
         * ten times the rows, the same number of queries"* — and asking each
         * type for its own count made this ten types, ten queries.
         *
         * `Deal` carries `BelongsToTeam`, so the global scope has already
         * constrained this to the resolved team: the count is per-team by
         * construction rather than by a `where` somebody has to remember, and
         * the leak that would follow from forgetting it is the one
         * `DealTypeIsolationTest` pins.
         */
        // Once, not once per row. The permission does not vary by row, which
        // is the half of each policy ability that costs a query.
        $mayManage = $request->user()?->can('create', DealType::class) ?? false;

        $dealCounts = Deal::query()
            ->select('deal_type_id')
            ->selectRaw('count(*) as total')
            ->whereIn('deal_type_id', $types->pluck('id'))
            ->groupBy('deal_type_id')
            ->pluck('total', 'deal_type_id');

        return Inertia::render('Settings/DealTypes', [
            'dealTypes' => $types->map(fn (DealType $type): array => [
                'id' => $type->getKey(),
                'name' => $type->name,
                'side' => $type->side->value,
                'sideLabel' => $type->side->label(),
                'isSystem' => $type->isSystem(),
                'archivedAt' => $type->archived_at?->toIso8601String(),
                /*
                 * Sent with the page rather than fetched on click. The warning
                 * has to be true at the moment somebody reads it, and a count
                 * fetched on click is a count fetched after they have decided.
                 *
                 * Null for a system type: a team does not archive one, so the
                 * question is not put — and answering it would mean counting
                 * across a row every team shares.
                 */
                'dealCount' => $type->isSystem()
                    ? null
                    : (int) ($dealCounts[$type->getKey()] ?? 0),
                /*
                 * The permission is asked once, above; the per-row half comes
                 * from the model, which is what the policy reads too.
                 *
                 * Asking the policy per row instead looks tidier and is a
                 * worse N+1 than the one this page already had:
                 * `ChecksTeamPermissions::membership()` re-queries the
                 * membership and eager-loads its roles and permissions on
                 * every call, so three abilities across ten rows was ~90
                 * queries. The policy's *permission* half does not vary by
                 * row, and its *predicate* half is `isManageableByTeam()` —
                 * so one of each is the whole answer.
                 */
                'canManage' => $mayManage && $type->isManageableByTeam(),
                'canRestore' => $mayManage && $type->isRestorable(),
            ])->all(),
            'sides' => DealSide::options(),
        ]);
    }

    public function store(StoreDealTypeRequest $request, AuditLogger $audit): RedirectResponse
    {
        $type = new DealType;

        /*
         * `team_id` is set here rather than filled from the request, for the
         * same reason `BelongsToTeam` does it for every other table: a request
         * body must not choose a tenant. `DealType` carries no `BelongsToTeam`
         * — a null `team_id` means "everybody's", which no global scope can
         * express — so this is the one place that has to remember, and
         * `team_id` is absent from `#[Fillable]` so a posted one is dropped
         * before it gets here.
         */
        $type->fill($request->validated());
        $type->forceFill([
            'team_id' => app(TeamContext::class)->requireId(DealType::class),
            'sort_order' => $this->nextSortOrder(),
        ])->save();

        $audit->record(
            action: 'deal_type.created',
            auditable: $type,
            teamId: $type->team_id,
            actorPersonId: $request->user()?->getKey(),
            after: ['name' => $type->name, 'side' => $type->side->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal type added.')]);

        return to_route('deal-types.index');
    }

    public function update(UpdateDealTypeRequest $request, DealType $dealType, AuditLogger $audit): RedirectResponse
    {
        $before = ['name' => $dealType->name, 'side' => $dealType->side->value];

        $dealType->fill($request->validated())->save();

        $audit->record(
            action: 'deal_type.updated',
            auditable: $dealType,
            teamId: $dealType->team_id,
            actorPersonId: $request->user()?->getKey(),
            before: $before,
            after: ['name' => $dealType->name, 'side' => $dealType->side->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal type updated.')]);

        return to_route('deal-types.index');
    }

    /**
     * Take it out of the pickers, leave every deal that used it alone.
     *
     * Audited because it changes what a team can start: after this, no new
     * deal can be opened on this type, and "why can nobody pick Rental
     * Placement any more" is a question somebody will ask in three months.
     */
    public function archive(DealType $dealType, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('archive', $dealType);

        $dealType->forceFill(['archived_at' => now()])->save();

        $audit->record(
            action: 'deal_type.archived',
            auditable: $dealType,
            teamId: $dealType->team_id,
            actorPersonId: request()->user()?->getKey(),
            after: ['archived_at' => $dealType->archived_at?->toIso8601String()],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal type archived.')]);

        return to_route('deal-types.index');
    }

    /**
     * Undo, because archiving is reversible and deleting is what is not.
     *
     * The whole argument for archiving over deletion is that it can be taken
     * back; a screen that archived with no way back would have talked somebody
     * out of a delete and given them the same problem.
     */
    public function restore(DealType $dealType, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('restore', $dealType);

        /*
         * The same question `store()` and `update()` ask, because restoring
         * asks it too.
         *
         * Both unique indexes are partial on `archived_at IS NULL`, so
         * clearing the column moves this row back *into* them. Archive "Land
         * Sale", add a new "Land Sale" — which is now allowed, and should be —
         * then press Restore, and without this the index refuses and an
         * unhandled `UniqueConstraintViolationException` lands as an error
         * modal rather than as a sentence on the row.
         *
         * A `ValidationException` rather than a silent no-op: the name is the
         * only thing in the way, and renaming the other one is a fix somebody
         * can carry out.
         */
        if (DealTypeRules::nameIsTaken($dealType->name, $dealType)) {
            throw ValidationException::withMessages([
                'restore' => "Another deal type is called “{$dealType->name}” now. "
                    .'Rename that one first, then restore this.',
            ]);
        }

        $dealType->forceFill(['archived_at' => null])->save();

        $audit->record(
            action: 'deal_type.restored',
            auditable: $dealType,
            teamId: $dealType->team_id,
            actorPersonId: request()->user()?->getKey(),
            after: ['archived_at' => null],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal type restored.')]);

        return to_route('deal-types.index');
    }

    /**
     * After everything this team already has, defaults included.
     *
     * A new type belongs at the end of the picker rather than interleaved with
     * the system defaults — somebody who wants it higher can reorder, and
     * reordering is not S76's job yet.
     */
    private function nextSortOrder(): int
    {
        $highest = DealType::query()
            ->visibleTo(app(TeamContext::class)->requireId(DealType::class))
            ->max('sort_order');

        // `-1` rather than `0` for the empty case, so the first type is 0 like
        // the seeded ones. Nothing is unique on `sort_order`, so two
        // concurrent creates landing on the same number is a tie in a picker,
        // not a constraint violation.
        return (int) ($highest ?? -1) + 1;
    }
}
