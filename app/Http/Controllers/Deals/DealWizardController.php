<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Actions\People\SavePerson;
use App\Actions\Properties\SaveProperty;
use App\Enums\DealDraftStep;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\CreateDraftClientRequest;
use App\Http\Requests\Deals\CreateDraftPropertyRequest;
use App\Http\Requests\Deals\SaveDealDraftStepRequest;
use App\Models\DealDraft;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Queries\PeopleDirectory;
use App\Queries\PropertyDirectory;
use App\Support\Deals\CreateDealFromDraft;
use App\Support\Deals\DealRoster;
use App\Support\Deals\RecordDealDraft;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Create a deal (Screen Inventory S14 · PRD §5.2, §4.3 F3.1 · issue #74).
 *
 * IA §7: a deal is **created**; a participant is **added**; a workflow is
 * **attached**. All three happen here, and the words are not interchangeable.
 *
 * ## The state that earns the screen is resume
 *
 * Issue #74: *"Heather is creating this on a phone, from a car, between
 * showings. A half-finished deal must survive a dropped connection."* So every
 * step posts, and the answer lands in `deal_drafts` before the response comes
 * back. There is no wizard state in the component beyond which panel is open —
 * closing the tab and returning lands on the step she left.
 *
 * ## Nothing is created until the last button
 *
 * Except the two things that are records in their own right. A client created
 * inline is a directory entry (PRD §5.2 step 2), and a property created inline
 * is a property — both exist whether or not the deal ever does, which is
 * correct: somebody who adds a contact and then abandons a deal has still
 * added a contact. The deal, the participant link and the workflow are the
 * transaction, and `CreateDealFromDraft` owns it.
 */
class DealWizardController extends Controller
{
    public function create(Request $request, RecordDealDraft $drafts, TeamContext $teams): Response
    {
        $this->authorize('create', DealDraft::class);

        /** @var Person $person */
        $person = $request->user();

        $draft = $drafts->open($person);
        $type = $draft->dealType();

        return Inertia::render('Deals/Create', [
            'draft' => [
                'step' => $draft->step->value,
                'dealTypeId' => $type?->getKey(),
                'name' => $draft->text('name'),
                'membershipId' => $draft->text('team_membership_id'),
                'participantRole' => $draft->text('participant_role'),
                'propertyId' => $draft->text('property_id'),
                'workflowTemplateId' => $draft->text('workflow_template_id'),
                /*
                 * Whether this draft was resumed rather than started, so the
                 * screen can say so. A wizard that silently reopened on step
                 * three would read as a bug the first time it happened.
                 */
                'resumed' => $draft->step !== DealDraftStep::Type || $draft->payload !== [],
            ],
            'steps' => array_map(
                fn (DealDraftStep $step): array => [
                    'value' => $step->value,
                    'label' => $step->label(),
                    'position' => $step->position(),
                ],
                DealDraftStep::cases(),
            ),
            'dealTypes' => DealType::query()
                ->visibleTo($teams->requireId(DealType::class))
                ->selectable()
                ->orderByRaw('team_id is not null')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (DealType $each): array => [
                    'id' => $each->getKey(),
                    'name' => $each->name,
                    'sideLabel' => $each->side->label(),
                ])->all(),
            /*
             * The role the client takes, decided by the deal type — Seller on
             * a sale, Buyer on a purchase. Null on a rental, where
             * `DealRoster` invents nothing and the wizard has to ask.
             */
            'impliedRole' => $this->impliedRole($type),
            'participantRoles' => ParticipantRole::options(),
            'propertyTypes' => PropertyType::options(),
            'propertyStatuses' => PropertyStatus::options(),
            'templates' => $this->templatesFor($type, $teams),
            // What is already picked, so a resumed draft can render its
            // choices without a second round trip.
            'chosen' => [
                'membership' => $this->chosenMembership($draft),
                'property' => $this->chosenProperty($draft),
            ],
        ]);
    }

    /** Save one step and return the draft, so the screen can advance. */
    public function update(SaveDealDraftStepRequest $request, RecordDealDraft $drafts): RedirectResponse
    {
        /** @var Person $person */
        $person = $request->user();

        $draft = $drafts->open($person);

        $this->authorize('update', $draft);

        $drafts->record($draft, $request->answers(), $request->step()->next());

        return back();
    }

    /**
     * A client created inline (PRD §5.2 step 2).
     *
     * Through `SavePerson`, the same action `/people` uses, so the person is a
     * directory entry like any other rather than a lesser row created by a
     * second code path that will drift — #60's conclusion, applied to the
     * screen it was written about.
     */
    public function storeClient(
        CreateDraftClientRequest $request,
        SavePerson $people,
        RecordDealDraft $drafts,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        // Authorized before anything is written. The other way round, a
        // refused request still left a person in the directory — the create
        // had already happened by the time the policy was asked.
        $draft = $drafts->open($person);

        $this->authorize('update', $draft);

        // `participant_role` belongs to the draft, not to the directory entry
        // — `SavePerson` would have nowhere to put it.
        $membership = $people->create([
            ...$request->safe()->except('participant_role'),
            'status' => PersonLifecycleState::Lead->value,
        ]);

        $drafts->record($draft, [
            'team_membership_id' => $membership->getKey(),
            'participant_role' => $request->validated('participant_role'),
        ], DealDraftStep::Property);

        return back();
    }

    /** A property created inline, through S37's own action. */
    public function storeProperty(
        CreateDraftPropertyRequest $request,
        SaveProperty $properties,
        RecordDealDraft $drafts,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        // Authorized first, for the same reason as `storeClient()`.
        $draft = $drafts->open($person);

        $this->authorize('update', $draft);

        $property = $properties->create($request->safe()->except('links'), $request->links());

        $drafts->record($draft, ['property_id' => $property->getKey()], DealDraftStep::Template);

        return back();
    }

    /** People this team knows, for step two's picker. */
    public function clients(Request $request, PeopleDirectory $directory): JsonResponse
    {
        $this->authorize('create', DealDraft::class);

        $memberships = $directory
            ->query(PersonSegment::All, trim((string) $request->query('q', '')))
            ->whereNull('team_memberships.revoked_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        return response()->json([
            'people' => $memberships->map(fn (TeamMembership $membership): array => [
                'id' => $membership->getKey(),
                'name' => $membership->fullName(),
                'email' => $membership->email,
            ])->values()->all(),
        ]);
    }

    /** Properties this team knows, for step three's picker. */
    public function properties(Request $request, PropertyDirectory $directory): JsonResponse
    {
        $this->authorize('create', DealDraft::class);

        $properties = $directory
            ->query(null, trim((string) $request->query('q', '')))
            ->withCount('dealLinks')
            ->orderBy('city')->orderBy('street')->orderBy('id')
            ->limit(20)
            ->get();

        return response()->json([
            'properties' => $properties->map(
                fn (Property $property): array => PropertyDirectory::row($property),
            )->values()->all(),
        ]);
    }

    /**
     * The last button: turn the draft into the deal.
     *
     * Redirects to the deal's people tab rather than to the deal itself,
     * because S15 — the overview — is #75 and does not exist. The client is
     * what the wizard just added, so it is the least wrong landing; swap this
     * for `deals.show` the day that screen lands.
     */
    public function store(Request $request, RecordDealDraft $drafts, CreateDealFromDraft $create): RedirectResponse
    {
        /** @var Person $person */
        $person = $request->user();

        $draft = $drafts->open($person);

        $this->authorize('update', $draft);

        $deal = $create->handle($draft);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal created.')]);

        return to_route('deals.people.index', $deal);
    }

    /** Give up on it. */
    public function destroy(Request $request, RecordDealDraft $drafts): RedirectResponse
    {
        /** @var Person $person */
        $person = $request->user();

        // `existing()`, not `open()`: giving up on a wizard you never started
        // should not create the row it then deletes, and a refusal should
        // leave nothing behind at all.
        $draft = $drafts->existing($person);

        if ($draft instanceof DealDraft) {
            $this->authorize('delete', $draft);

            $drafts->abandon($draft);
        }

        return to_route('deals.index');
    }

    /** @return array{value: string, label: string}|null */
    private function impliedRole(?DealType $type): ?array
    {
        $role = DealRoster::impliedRole($type);

        return $role instanceof ParticipantRole
            ? ['value' => $role->value, 'label' => $role->label()]
            : null;
    }

    /**
     * The templates this deal type can use.
     *
     * Filtered by `deal_type_workflow_template` when the type has any
     * associations, and otherwise everything the team can see: a team that has
     * not wired its templates to its types should not meet an empty picker
     * with no explanation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function templatesFor(?DealType $type, TeamContext $teams): array
    {
        if (! $type instanceof DealType) {
            return [];
        }

        $query = WorkflowTemplate::query()
            ->visibleTo($teams->requireId(WorkflowTemplate::class))
            ->where('is_active', true)
            ->withCount('stageTemplates');

        if ($type->workflowTemplates()->exists()) {
            $query->whereHas('dealTypes', fn ($types) => $types->whereKey($type->getKey()));
        }

        return $query->orderBy('name')->get()->map(fn (WorkflowTemplate $template): array => [
            'id' => $template->getKey(),
            'name' => $template->name,
            'description' => $template->description,
            'stageCount' => (int) ($template->stage_templates_count ?? 0),
            'isSystem' => $template->isSystem(),
        ])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function chosenMembership(DealDraft $draft): ?array
    {
        $id = $draft->text('team_membership_id');

        if ($id === null) {
            return null;
        }

        $membership = TeamMembership::query()->whereKey($id)->first();

        return $membership instanceof TeamMembership
            ? ['id' => $membership->getKey(), 'name' => $membership->fullName(), 'email' => $membership->email]
            : null;
    }

    /** @return array<string, mixed>|null */
    private function chosenProperty(DealDraft $draft): ?array
    {
        $id = $draft->text('property_id');

        if ($id === null) {
            return null;
        }

        $property = Property::query()->whereKey($id)->withCount('dealLinks')->first();

        return $property instanceof Property ? PropertyDirectory::row($property) : null;
    }
}
