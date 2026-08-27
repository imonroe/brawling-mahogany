<?php

declare(strict_types=1);

namespace App\Support\StatusPage;

use App\Mail\StatusPageLinkMail;
use App\Models\Deal;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Mail;

/**
 * *"Request a new one"*, resolved from nothing but an email address (#110).
 *
 * S64's escape hatch has to work for a client who *"knows nothing but their
 * email address"* — no deal id, no team, no session. So this is the one place
 * in the product that starts from an address and works outwards, and every
 * narrowing it applies is a rule about what somebody may be handed.
 *
 * ## It re-issues access somebody already had, and never grants new access
 *
 * The address is matched against `status_page_links` that already exist — not
 * against the people directory. That distinction is the whole safety of the
 * endpoint: asking cannot get you onto a deal, it can only get you back onto
 * one you were already on. A stranger who guesses a client's address learns
 * nothing and gains nothing; the client whose link expired gets a new one.
 *
 * ## Which deal, when there are several
 *
 * All of them, as separate emails. A client selling one house and buying
 * another has two status pages and no way to tell this endpoint which they
 * meant — and a product that silently picked the most recent would send them
 * to the wrong transaction half the time.
 *
 * ## No team is resolved, so each send establishes its own
 *
 * The links carry the tenant, and the mail has to be rendered in the team's
 * frame — so each is sent inside `runFor()`, which is the escape hatch ADR
 * 0002 provides for exactly this: a caller that legitimately spans tenants
 * because the request has none.
 */
final class DispatchStatusPageLink
{
    public function __construct(
        private readonly IssueStatusPageLink $links,
        private readonly TeamContext $teams,
    ) {}

    /**
     * @return int how many links went out — for a console caller, never for a
     *             web response, which says the same thing either way
     */
    public function forAddress(string $address): int
    {
        $sent = 0;

        foreach ($this->grantsFor($address) as $link) {
            $deal = $link->deal;
            $membership = $link->membership;
            $team = $link->team;

            if (! $deal instanceof Deal || ! $membership instanceof TeamMembership || ! $team instanceof Team) {
                continue;
            }

            $this->teams->runFor($team, function () use ($deal, $membership, $team): void {
                $issued = $this->links->issue($deal, $membership);

                Mail::to((string) $membership->email)->send(new StatusPageLinkMail(
                    team: $team,
                    clientName: $membership->fullName(),
                    url: $issued->url(),
                    minutes: StatusPageLink::LINK_MINUTES,
                    what: $deal->dealType?->side->clientLabel() ?? 'Your Transaction',
                ));
            });

            $sent++;
        }

        return $sent;
    }

    /**
     * The deals this address has previously been given access to.
     *
     * One row per deal, newest first, and **revoked grants are excluded**: an
     * agent who took somebody's access away must not have it handed back by an
     * endpoint anybody can hit. Expired and used ones are included, because
     * those are precisely the people this exists for.
     *
     * `withoutTeamScope()` because there is no team to resolve — the address
     * is all there is, and the rows are what establish the tenant.
     *
     * @return list<StatusPageLink>
     */
    private function grantsFor(string $address): array
    {
        $links = StatusPageLink::withoutTeamScope()
            ->live()
            ->whereHas('membership', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereRaw('lower(email) = ?', [$address]))
            ->with(['deal.dealType', 'membership', 'team'])
            ->orderByDesc('created_at')
            ->get();

        /*
         * One per deal. A client who has been re-issued a link four times has
         * four rows, and four emails would be the mail cannon the rate limit
         * above exists to prevent, pointed at somebody by the product itself.
         */
        $byDeal = [];

        foreach ($links as $link) {
            $byDeal[(string) $link->deal_id] ??= $link;
        }

        return array_values($byDeal);
    }
}
