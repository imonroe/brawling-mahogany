<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\DealSide;
use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is on a deal — reading it, adding to it, and saying what is missing
 * (S19, S25 · issue #60).
 *
 * A service rather than model methods because two of the three operations are
 * genuinely more than a write: adding a participant may demote an incumbent
 * primary, and *"which expected role is absent"* is a question about the deal
 * type rather than about any row that exists.
 */
final class DealRoster
{
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * Add somebody to a deal in a role.
     *
     * `is_primary` demotes the incumbent **in the same transaction** as the
     * insert. The partial unique index would otherwise refuse the second
     * primary, and a 500 on "make this one the main contact" is a worse answer
     * than doing the obvious thing — there is only ever one main contact, so
     * setting a new one plainly means replacing the old one.
     */
    public function add(
        Deal $deal,
        TeamMembership $membership,
        ParticipantRole $role,
        bool $isPrimary = false,
        ?string $notes = null,
    ): DealParticipant {
        return DB::transaction(function () use ($deal, $membership, $role, $isPrimary, $notes): DealParticipant {
            if ($isPrimary) {
                DealParticipant::query()
                    ->where('deal_id', $deal->getKey())
                    ->inRole($role)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $participant = new DealParticipant;
            $participant->forceFill([
                'team_id' => $deal->team_id,
                'deal_id' => $deal->getKey(),
                'team_membership_id' => $membership->getKey(),
                'participant_role' => $role->value,
                'is_primary' => $isPrimary,
                'notes' => $notes,
            ]);

            /*
             * `StoreParticipantRequest` asks first; this catches what asking
             * cannot close.
             *
             * Between the rule's `select` and this `insert` there is a window,
             * and the shape of this screen invites it: the candidate list with
             * its held-roles is fetched on a debounce, so two people on one
             * deal — or two tabs — genuinely race here. The same catch covers
             * `deal_participants_one_primary_per_role`, which two simultaneous
             * "make this the main contact" clicks can reach the same way.
             *
             * A sentence rather than a stack trace, and the same sentence the
             * rule would have given.
             */
            try {
                $participant->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'participant_role' => $membership->fullName()
                        .' is already on this deal as '.$role->label().'.',
                ]);
            }

            /*
             * Timelined, not audited. Who is on a deal is ordinary work a team
             * wants to read back — "when did the lender join" — rather than a
             * security event. `CLAUDE.md`: Activity is not History is not
             * Audit, and the two have different readers and retention.
             */
            $this->activity->record(
                subject: $deal,
                eventType: 'participant.added',
                summary: "Added {$membership->fullName()} as {$role->label()}",
            );

            return $participant;
        });
    }

    /**
     * Change a participant's role, primacy, or notes.
     *
     * Two indexes can refuse this, and both mean something a person can act
     * on rather than a bug: moving somebody into a role they already hold on
     * this deal is the meaningless duplicate, and promoting them to primary
     * needs the incumbent demoted first. The demotion happens here; the
     * duplicate is refused with a sentence.
     */
    public function replace(
        DealParticipant $participant,
        ParticipantRole $role,
        ?bool $isPrimary = null,
        ?string $notes = null,
    ): DealParticipant {
        /*
         * Nulls mean "not sent", not "set to empty".
         *
         * `SavePerson::applyIdentity()` states the rule this follows: *a
         * partial update must not blank a column the screen did not show*. The
         * first version defaulted both to false/null and `forceFill`ed, so a
         * PATCH carrying only a role demoted a main contact and erased their
         * notes. Today's UI always sends all three, which is exactly why the
         * bug would have waited for the second caller.
         */
        $isPrimary ??= $participant->is_primary;
        $notes ??= $participant->notes;

        return DB::transaction(function () use ($participant, $role, $isPrimary, $notes): DealParticipant {
            $roleChanged = $participant->participant_role !== $role;

            if ($roleChanged && $this->alreadyHolds($participant, $role)) {
                throw ValidationException::withMessages([
                    'participant_role' => $participant->fullName()
                        .' is already on this deal as '.$role->label().'.',
                ]);
            }

            if ($isPrimary) {
                DealParticipant::query()
                    ->where('deal_id', $participant->deal_id)
                    ->inRole($role)
                    ->whereKeyNot($participant->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $participant->forceFill([
                'participant_role' => $role->value,
                'is_primary' => $isPrimary,
                'notes' => $notes,
            ]);

            try {
                $participant->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'participant_role' => $participant->fullName()
                        .' is already on this deal as '.$role->label().'.',
                ]);
            }

            if ($roleChanged) {
                $this->activity->record(
                    subject: $participant->deal,
                    eventType: 'participant.role_changed',
                    summary: $participant->fullName().' is now '.$role->label(),
                );
            }

            return $participant;
        });
    }

    /** Is this membership already on this deal in that role? */
    private function alreadyHolds(DealParticipant $participant, ParticipantRole $role): bool
    {
        return DealParticipant::query()
            ->where('deal_id', $participant->deal_id)
            ->where('team_membership_id', $participant->team_membership_id)
            ->inRole($role)
            ->whereKeyNot($participant->getKey())
            ->exists();
    }

    /**
     * Detach, which is not delete (IA §7).
     *
     * The membership stays in the directory and on every other deal. Taking
     * the opposing agent off a deal that fell through must never be a way to
     * lose them from the address book.
     */
    public function remove(DealParticipant $participant): void
    {
        DB::transaction(function () use ($participant): void {
            $name = $participant->fullName();
            $role = $participant->participant_role->label();

            $participant->delete();

            $this->activity->record(
                subject: $participant->deal,
                eventType: 'participant.removed',
                summary: "Removed {$name} as {$role}",
            );
        });
    }

    /**
     * Roles this deal ought to have somebody in, and does not.
     *
     * **Derived from the deal's side, and deliberately small.** A sell-side
     * deal without a Seller is a deal with nobody to send the client updates
     * to; a buy-side deal without a Buyer is the same. Rental placement and
     * "other" are left alone rather than guessed at — PRD §6.3 has no Tenant
     * or Landlord role, so any expectation here would be invented, and a
     * screen asserting a wrong requirement is worse than one asserting none.
     *
     * Slice 3 is where this grows real teeth: a gate on the lender, or a
     * message whose recipient rule is "the Seller", is a *requirement* rather
     * than an expectation, and those come from the workflow rather than from
     * the deal type. Issue #60's point stands either way — surfacing the gap
     * on S19 is cheaper than an advance being refused for it later.
     *
     * @return list<ParticipantRole>
     */
    public function missingExpectedRoles(Deal $deal): array
    {
        $expected = $this->expectedRoles($deal);

        if ($expected === []) {
            return [];
        }

        /*
         * `->value` on the way out, because `pluck` returns the **cast**
         * column — `ParticipantRole` instances, not strings. Comparing those
         * to `$role->value` with a strict `in_array` never matches, so every
         * expected role reads as missing however many are present. The screen
         * said "no Seller yet" next to the Seller.
         */
        $present = DealParticipant::query()
            ->where('deal_id', $deal->getKey())
            ->pluck('participant_role')
            ->map(fn (ParticipantRole $role): string => $role->value)
            ->all();

        return array_values(array_filter(
            $expected,
            fn (ParticipantRole $role): bool => ! in_array($role->value, $present, true),
        ));
    }

    /**
     * @return list<ParticipantRole>
     */
    public function expectedRoles(Deal $deal): array
    {
        return match ($deal->dealType->side) {
            DealSide::Sell => [ParticipantRole::Seller],
            DealSide::Buy => [ParticipantRole::Buyer],
            // Nothing invented. See the note on missingExpectedRoles().
            DealSide::Rent, DealSide::Other => [],
        };
    }

    /**
     * The roles each of these memberships already holds on this deal.
     *
     * S25 warns with this rather than refusing: the same person in two roles
     * on one deal is unusual, not impossible, and issue #60 asks for a warning
     * *"rather than duplicating"*. The exact same person in the same role
     * twice is the meaningless case, and the database refuses that outright.
     *
     * **Plural, and one query.** The per-membership version of this ran inside
     * the `map()` over the candidate list — one extra query per row, up to
     * twenty, fired on every keystroke of a 250ms debounce. It is the shape
     * `PeopleIndexBudgetTest` exists to catch, on the endpoint least able to
     * afford it, and `ParticipantsBudgetTest` now holds it.
     *
     * @param  EloquentCollection<int, TeamMembership>  $memberships
     * @return array<string, list<string>> membership id => role labels
     */
    public function rolesAlreadyHeld(Deal $deal, EloquentCollection $memberships): array
    {
        if ($memberships->isEmpty()) {
            return [];
        }

        $held = [];

        DealParticipant::query()
            ->where('deal_id', $deal->getKey())
            ->whereIn('team_membership_id', $memberships->modelKeys())
            ->get()
            ->each(function (DealParticipant $participant) use (&$held): void {
                $held[$participant->team_membership_id][] = $participant->participant_role->label();
            });

        return $held;
    }
}
