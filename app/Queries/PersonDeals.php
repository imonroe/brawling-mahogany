<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\DealParticipant;

/**
 * The deals a logged contact can be attached to (S26 · PRD F2.5).
 *
 * F2.5 logs a contact *"against a person and optionally a deal"*, and the
 * useful set of deals is the ones that person is actually on — "I called Emily
 * about Main St" is a call to a participant. Offering every open deal in the
 * team instead would make the second click a search.
 *
 * One class rather than a query in each controller, because two screens ask:
 * the person record (S31) and the shell's search results, which have to agree
 * about what "attachable" means or the modal offers different deals depending
 * on where it was opened from.
 */
final class PersonDeals
{
    /**
     * @param  iterable<string>  $membershipIds
     * @return array<string, list<array{id: string, name: string}>> membership id => deals
     */
    public static function forMemberships(iterable $membershipIds): array
    {
        $ids = collect($membershipIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $participations = DealParticipant::query()
            ->whereIn('team_membership_id', $ids->all())
            /*
             * Only participations whose deal still exists.
             *
             * Soft-deleting a deal does not delete its participants, and
             * `DealParticipant::deal()` carries no `withTrashed()` — so the
             * relation comes back null and `->displayName()` below is a fatal
             * on a screen a team reaches by clicking a person. A deleted deal
             * has no business in the Log-contact dialog either way, so the
             * filter is the fix rather than a null check around the symptom.
             */
            ->whereHas('deal')
            // One query for the deals behind however many participations came
            // back, rather than one per row.
            ->with('deal')
            ->get();

        /** @var array<string, list<array{id: string, name: string}>> $deals */
        $deals = [];

        foreach ($participations as $participation) {
            $membershipId = (string) $participation->team_membership_id;
            $dealId = (string) $participation->deal_id;

            /*
             * The same person can hold two roles on one deal — S25 allows that
             * deliberately, because people do hold two parts in one
             * transaction — and the modal must not offer the deal twice.
             */
            foreach ($deals[$membershipId] ?? [] as $known) {
                if ($known['id'] === $dealId) {
                    continue 2;
                }
            }

            $deals[$membershipId][] = [
                'id' => $dealId,
                'name' => $participation->deal->displayName(),
            ];
        }

        return $deals;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function forMembership(string $membershipId): array
    {
        return self::forMemberships([$membershipId])[$membershipId] ?? [];
    }
}
