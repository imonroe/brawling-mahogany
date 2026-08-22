<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Actions\People\SavePerson;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreParticipantRequest;
use App\Http\Requests\Deals\UpdateParticipantRequest;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use App\Queries\PeopleDirectory;
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

        // PRD §6.3 order, as a lookup rather than a search per group.
        $rolePositions = array_flip(array_column(ParticipantRole::cases(), 'value'));

        return Inertia::render('Deals/People', [
            'deal' => [
                'id' => $deal->getKey(),
                'name' => $deal->displayName(),
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
                /*
                 * In PRD §6.3 order — Seller and Buyer first — rather than in
                 * whatever order somebody happened to add people. A deal where
                 * the inspector was booked before the listing agreement was
                 * signed should not render Inspector above Seller.
                 */
                /*
                 * Every key here is a valid case already: `groupBy` above
                 * reads `participant_role->value`, and the cast throws on
                 * anything else before this runs. So no fallback — an earlier
                 * version had one with a comment claiming it kept an unknown
                 * role from sorting in front of Seller, which it could not do,
                 * because the sort never sees one. What actually guards this
                 * is `Rule::enum` on the way in and the cast on the way out.
                 */
                ->sortBy(fn ($group, string $role): int => $rolePositions[$role])
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
    public function candidates(
        Request $request,
        Deal $deal,
        DealRoster $roster,
        PeopleDirectory $directory,
    ): JsonResponse {
        $this->authorize('create', [DealParticipant::class, $deal]);

        /*
         * The directory's own search, not a second one.
         *
         * The first version re-implemented it here and had already drifted —
         * no phone, no `coalesce`, so a null surname dropped the row. ADR
         * 0002's own guidance is to reach for the thing that already carries
         * the behaviour, and this is that applied to a query rather than to a
         * tenancy layer.
         */
        $memberships = $directory
            ->query(PersonSegment::All, trim((string) $request->query('q', '')))
            ->whereNull('team_memberships.revoked_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        // One query for the whole page of candidates, not one per row.
        $heldRoles = $roster->rolesAlreadyHeld($deal, $memberships);

        return response()->json([
            'candidates' => $memberships->map(fn (TeamMembership $membership): array => [
                'id' => $membership->getKey(),
                'name' => $membership->fullName(),
                'email' => $membership->email,
                'heldRoles' => $heldRoles[$membership->getKey()] ?? [],
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
        /*
         * Only the keys that arrived, by presence rather than by value.
         *
         * `notes: ''` reaches here as null — `ConvertEmptyStringsToNull` runs
         * before every request — so a nullable argument could not tell
         * "clear it" from "leave it". `has()` still distinguishes the two,
         * because the key is present either way.
         */
        $changes = [];

        // A null `notes` is an instruction — clear it. A null `is_primary` is
        // not: there is no third state for a checkbox, and `boolean(null)` is
        // `false`, which would demote a main contact for a key that said
        // nothing.
        if ($request->has('is_primary') && $request->input('is_primary') !== null) {
            $changes['is_primary'] = $request->boolean('is_primary');
        }

        if ($request->has('notes')) {
            $changes['notes'] = $request->validated('notes');
        }

        $roster->replace(
            participant: $participant,
            role: ParticipantRole::from((string) $request->validated('participant_role')),
            changes: $changes,
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
