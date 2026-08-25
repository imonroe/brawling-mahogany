<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Enums\RecipientRuleType;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Support\Collection;

/**
 * A recipient rule plus a deal, resolved to actual people (PRD §7.12).
 *
 * The other half of the correction PRD §7.12 makes. The rule is stored, and
 * this is the only thing that turns it into addresses — so *"the Seller"*
 * means the seller **on this deal**, resolved at the moment of sending rather
 * than at the moment of writing.
 *
 * ## Resolving to nobody is an answer, and callers have to handle it
 *
 * A rule can legitimately find nobody: a template addressed to the Lender on a
 * cash purchase, a deal whose participants have not been entered yet. This
 * returns an empty collection and says so; it does not throw, because that is
 * a normal state of a normal deal. What must never happen is a send path
 * treating an empty result as success — PRD §1.1's second question is *"has
 * the client been told?"* and nothing is worse than a silent no.
 *
 * ## Everything comes back as a `TeamMembership`
 *
 * Not a `Person`. Slice 2 moved name, email and phone onto the membership
 * (#140), so the membership is the only thing that knows what to call somebody
 * and where to reach them — and it is team-scoped, which a `Person` is not.
 */
final class ResolveRecipients
{
    /**
     * @return Collection<int, TeamMembership>
     */
    public function for(RecipientRule $rule, Deal $deal, Team $team): Collection
    {
        return match ($rule->type) {
            RecipientRuleType::ParticipantRole => $this->participantsInRole($rule, $deal),
            RecipientRuleType::PrimaryContact => $this->primaryContact($deal),
            RecipientRuleType::TeamOwner => $this->owners($team),
        };
    }

    /**
     * @return Collection<int, TeamMembership>
     */
    private function participantsInRole(RecipientRule $rule, Deal $deal): Collection
    {
        $role = $rule->participantRole;

        if ($role === null) {
            return collect();
        }

        $deal->loadMissing('participants.membership');

        return $deal->participants
            ->filter(fn (DealParticipant $participant): bool => $participant->participant_role === $role)
            ->map(fn (DealParticipant $participant): ?TeamMembership => $participant->membership)
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, TeamMembership>
     */
    private function primaryContact(Deal $deal): Collection
    {
        $deal->loadMissing('participants.membership');

        /*
         * The same order `DealHeader::clientName()` and
         * `MergeFields::primaryContact()` use. Three readings of "the main
         * contact" that could disagree is exactly the shape #75 folded away in
         * the header, and the consequence here is worse: a greeting addressed
         * to one person and an envelope addressed to another.
         */
        $participant = $deal->participants->firstWhere('is_primary', true)
            ?? $deal->participants->first();

        $membership = $participant instanceof DealParticipant ? $participant->membership : null;

        return $membership instanceof TeamMembership ? collect([$membership]) : collect();
    }

    /**
     * The team's owners, by name rather than through a rule.
     *
     * Public because the sandbox rail needs the same answer for a different
     * question: F5.9 redirects **every** message to the team owner, whoever
     * the message was addressed to, and asking that through a recipient rule
     * would be asking the wrong object. One implementation either way — the
     * `holdingSystemRole()` subtlety below is not worth having twice.
     *
     * @return Collection<int, TeamMembership>
     */
    public function teamOwners(Team $team): Collection
    {
        return $this->owners($team);
    }

    /**
     * @return Collection<int, TeamMembership>
     */
    private function owners(Team $team): Collection
    {
        /*
         * The system Team Owner role, with a null `team_id` — never a role
         * whose *key* is `team_owner`. `Str::slug('Team Owner', '_')` is
         * exactly that key, so a team composing a role of that name would
         * otherwise have its own members counted as owners. S75 records the
         * same finding on `RevokeMembership`; `scopeHoldingSystemRole()` is
         * the shared answer.
         */
        return TeamMembership::query()
            ->where('team_id', $team->getKey())
            ->active()
            ->holdingSystemRole(SystemRole::TeamOwner->value)
            ->get();
    }
}
