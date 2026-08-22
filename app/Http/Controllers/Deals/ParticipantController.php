<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Actions\People\SavePerson;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreParticipantRequest;
use App\Http\Requests\Deals\UpdateParticipantRequest;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use App\Support\Deals\DealRoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deal people (S19) and add participant (S25) — issue #60.
 *
 * ## Why "missing required role" is on this screen
 *
 * Issue #60: a workflow whose stage has a gate on the lender, or a message
 * whose recipient rule is "the Seller", needs that participant to exist.
 * Surfacing the gap here is cheaper than an advance being refused for it
 * three weeks later, with nobody remembering why.
 *
 * ## The two paths through S25 are one modal
 *
 * PRD §5.2 says the client is added *"from imported contacts or created
 * inline"*. Creating inline goes through `SavePerson`, the same action
 * `/people` uses — so a person created from a deal is a directory entry like
 * any other, with the same activity trail, rather than a lesser row created
 * by a second code path that will drift.
 */
class ParticipantController extends Controller
{
    public function index(Deal $deal, DealRoster $roster): Response
    {
        $this->authorize('viewAny', [DealParticipant::class, $deal]);

        $deal->load('participants.membership', 'dealType');

        return Inertia::render('Deals/People', [
            'deal' => [
                'id' => $deal->getKey(),
                'name' => $deal->displayName(),
                'state' => $deal->state->value,
                'sideLabel' => $deal->dealType->side->label(),
            ],
            /*
             * Grouped here rather than in the component. The grouping is the
             * screen's whole shape (issue #60: "groups by role"), and a
             * template that regrouped a flat list on every render would also
             * have to keep the role order in a second place.
             */
            'roles' => $deal->participants
                ->groupBy(fn (DealParticipant $participant): string => $participant->participant_role->value)
                ->map(fn ($group, string $role): array => [
                    'role' => $role,
                    'label' => ParticipantRole::from($role)->label(),
                    'people' => $group->map(fn (DealParticipant $participant): array => [
                        'id' => $participant->getKey(),
                        'name' => $participant->fullName(),
                        'email' => $participant->membership->email,
                        'phone' => $participant->membership->phone,
                        'isPrimary' => $participant->is_primary,
                        'notes' => $participant->notes,
                        'personUrl' => route('people.show', $participant->membership),
                    ])->values()->all(),
                ])->values()->all(),
            /*
             * Named, not counted. "This deal has no Seller" is actionable;
             * "1 role missing" sends somebody looking for which.
             */
            'missingRoles' => array_map(
                fn (ParticipantRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                $roster->missingExpectedRoles($deal),
            ),
            'participantRoles' => ParticipantRole::options(),
        ]);
    }

    /**
     * Who this team knows, for the modal's search half.
     *
     * Returns the roles each candidate already holds **on this deal**, so the
     * modal can warn before the submit rather than after it — the same
     * before-the-choice rule S76 follows for its in-use count.
     */
    public function candidates(Request $request, Deal $deal, DealRoster $roster): JsonResponse
    {
        $this->authorize('create', [DealParticipant::class, $deal]);

        $term = trim((string) $request->query('q', ''));

        $memberships = TeamMembership::query()
            ->whereNull('revoked_at')
            ->when($term !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->whereRaw('lower(first_name) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(last_name) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower($term).'%']),
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        return response()->json([
            'candidates' => $memberships->map(fn (TeamMembership $membership): array => [
                'id' => $membership->getKey(),
                'name' => $membership->fullName(),
                'email' => $membership->email,
                'heldRoles' => $roster->rolesAlreadyHeld($deal, $membership)
                    ->map(fn (ParticipantRole $role): string => $role->label())
                    ->all(),
            ])->values()->all(),
        ]);
    }

    public function store(
        StoreParticipantRequest $request,
        Deal $deal,
        DealRoster $roster,
        SavePerson $people,
    ): RedirectResponse {
        $membership = $request->membership();

        if (! $membership instanceof TeamMembership) {
            // Created inline, through the same action `/people` uses.
            $membership = $people->create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'status' => PersonLifecycleState::Lead->value,
            ]);
        }

        $roster->add(
            deal: $deal,
            membership: $membership,
            role: $request->role(),
            isPrimary: (bool) $request->validated('is_primary', false),
            notes: $request->validated('notes'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Participant added.')]);

        return to_route('deals.people.index', $deal);
    }

    public function update(
        UpdateParticipantRequest $request,
        Deal $deal,
        DealParticipant $participant,
        DealRoster $roster,
    ): RedirectResponse {
        $roster->replace(
            participant: $participant,
            role: ParticipantRole::from((string) $request->validated('participant_role')),
            isPrimary: (bool) $request->validated('is_primary', false),
            notes: $request->validated('notes'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Participant updated.')]);

        return to_route('deals.people.index', $deal);
    }

    /** IA §7: **Remove** detaches, **Delete** destroys. This detaches. */
    public function remove(Deal $deal, DealParticipant $participant, DealRoster $roster): RedirectResponse
    {
        $this->authorize('remove', $participant);

        $roster->remove($participant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Participant removed.')]);

        return to_route('deals.people.index', $deal);
    }
}
