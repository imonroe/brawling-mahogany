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
use PDOException;

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
            } catch (UniqueConstraintViolationException $violation) {
                throw ValidationException::withMessages([
                    'participant_role' => self::explain($violation, $membership->fullName(), $role),
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
    /**
     * @param  array<string, mixed>  $changes  only the keys the request sent
     */
    public function replace(
        DealParticipant $participant,
        ParticipantRole $role,
        array $changes = [],
    ): DealParticipant {
        /*
         * **The keys present, not their values.**
         *
         * `SavePerson::applyIdentity()` states the rule this follows: *a
         * partial update must not blank a column the screen did not show*. The
         * first version defaulted to false/null and `forceFill`ed, so a PATCH
         * carrying only a role demoted a main contact and erased their notes.
         *
         * The second version passed nullable arguments and read null as "not
         * sent" — which cannot work, because Laravel's global
         * `ConvertEmptyStringsToNull` turns `notes: ''` into null before
         * anything here sees it. That made "clear the notes" indistinguishable
         * from "leave them alone", so the notes became unclearable: the mirror
         * image of the bug the fix was for.
         *
         * An array of the keys that arrived is the only shape that carries the
         * distinction, because presence survives what value-coercion erases.
         */
        $isPrimary = array_key_exists('is_primary', $changes)
            ? (bool) $changes['is_primary']
            : $participant->is_primary;

        $notes = array_key_exists('notes', $changes)
            ? ($changes['notes'] === null ? null : (string) $changes['notes'])
            : $participant->notes;

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
            } catch (UniqueConstraintViolationException $violation) {
                throw ValidationException::withMessages([
                    'participant_role' => self::explain($violation, $participant->fullName(), $role),
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

    /**
     * Which index refused, and what that means in a sentence.
     *
     * Two of them raise the same exception class here, and the difference
     * matters to the person reading it: telling somebody *"Sam is already on
     * this deal as Seller"* when Sam is not on the deal at all — and what
     * actually happened is that a colleague made somebody else the main
     * contact half a second earlier — sends them looking for a row that is not
     * there.
     *
     * Matching on the constraint name rather than on the message, because the
     * message is a driver's and the name is ours.
     *
     * Public so it can be pinned. The branch is only reachable in production
     * by losing a race, which is exactly why a test through the route cannot
     * hold it — and round 3 proved that a test asserting *Postgres* includes
     * the name passes whether or not this method reads it.
     */
    public static function explain(
        UniqueConstraintViolationException $violation,
        string $name,
        ParticipantRole $role,
    ): string {
        /*
         * The **driver's** message, not the exception's.
         *
         * Laravel appends the interpolated SQL to `getMessage()`, so matching
         * there means matching against a string that carries the request's own
         * data — a `notes` value containing the index name would flip the
         * branch. `errorInfo[2]` is what Postgres said, and nothing else.
         */
        $driver = $violation->getPrevious();

        $driverMessage = $driver instanceof PDOException && is_array($driver->errorInfo)
            ? (string) ($driver->errorInfo[2] ?? '')
            : '';

        if (str_contains($driverMessage, 'deal_participants_one_primary_per_role')) {
            return 'Somebody else was just made the main contact for '.$role->label()
                .' on this deal. Reload to see who.';
        }

        return $name.' is already on this deal as '.$role->label().'.';
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
            /*
             * Deterministic, and in the same PRD §6.3 order the people tab
             * groups by — so "already on this deal as Seller, Attorney" reads
             * the same way twice and matches the screen behind the modal.
             *
             * `array[?, ?, …]` with one placeholder per case rather than a
             * hand-built `'{a,b,c}'` literal. The literal was correctly bound
             * and not injectable, but it did no element quoting — so a future
             * role value containing a comma, brace or quote would parse into
             * the wrong array and mis-order silently, with no error. This puts
             * no constraint on what a value may contain.
             */
            ->orderByRaw(
                'array_position(array['.implode(',', array_fill(0, count(ParticipantRole::cases()), '?')).'], participant_role)',
                array_column(ParticipantRole::cases(), 'value'),
            )
            ->get()
            ->each(function (DealParticipant $participant) use (&$held): void {
                $held[$participant->team_membership_id][] = $participant->participant_role->label();
            });

        return $held;
    }
}
