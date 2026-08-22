<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\DealSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreDealTypeRequest;
use App\Http\Requests\Settings\UpdateDealTypeRequest;
use App\Models\DealType;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
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
 * So there is no destroy action here, deliberately, and `DealType` carries no
 * `deleted_at` for a team to reach. Archiving keeps existing deals labelled
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
    public function index(TeamContext $teams): Response
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

        return Inertia::render('Settings/DealTypes', [
            'dealTypes' => $types->map(fn (DealType $type): array => [
                'id' => $type->getKey(),
                'name' => $type->name,
                'side' => $type->side->value,
                'sideLabel' => $type->side->label(),
                'isSystem' => $type->isSystem(),
                'archivedAt' => $type->archived_at?->toIso8601String(),
                /*
                 * Counted per row rather than by the screen asking later.
                 * The warning has to be true at the moment somebody reads it,
                 * and a count fetched on click is a count fetched after they
                 * have decided.
                 */
                'liveDealCount' => $type->isSystem() ? null : $type->liveDealCount(),
                'canEdit' => $type->isArchived() === false && ! $type->isSystem(),
                'canArchive' => $type->isArchivable(),
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
        return (int) DealType::query()
            ->visibleTo(app(TeamContext::class)->requireId(DealType::class))
            ->max('sort_order') + 1;
    }
}
