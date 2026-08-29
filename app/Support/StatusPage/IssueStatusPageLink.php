<?php

declare(strict_types=1);

namespace App\Support\StatusPage;

use App\Enums\ActivitySource;
use App\Models\Deal;
use App\Models\Person;
use App\Models\StatusPageLink;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of `status_page_links` (PRD §4.7 F7.1, §9 · issue #110).
 *
 * Three verbs — issue, redeem, revoke — and each of them writes more than a
 * row. #110's definition of done: *"every issuance and use writes an activity
 * event"*, and PRD §9 puts client access in the audit log too. A controller
 * that saved the row would produce a working link and no record that anybody
 * had been given one.
 *
 * ## Tokens are 256 bits of `random_bytes`, never anything derived
 *
 * F7.7: *"ULIDs only in client-facing routes… nothing sequential, nothing
 * guessable, nothing that leaks how many deals exist."* A random token is
 * stronger than a ULID on all three counts — a ULID's leading bits are a
 * timestamp — so the route carries one of these instead.
 */
final class IssueStatusPageLink
{
    public function __construct(
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A fresh link for one person on one deal.
     *
     * ## Every issue revokes what came before
     *
     * A client who asks for a new link because the old one expired has two
     * working sessions otherwise, and the second is on a device they may no
     * longer have. Revoking is also what makes *"request a new one"* on S64
     * safe to leave unauthenticated: the worst an attacker can do by asking is
     * invalidate a link and cause an email to an address they do not control.
     */
    public function issue(Deal $deal, TeamMembership $membership, ?Person $actor = null): IssuedLink
    {
        $token = Str::random(43);

        $link = new StatusPageLink;

        DB::transaction(function () use ($deal, $membership, $token, $link, $actor): void {
            StatusPageLink::query()
                ->where('deal_id', $deal->getKey())
                ->where('team_membership_id', $membership->getKey())
                ->live()
                ->update([
                    'revoked_at' => now(),
                    'revoked_by' => $actor?->getKey(),
                    'updated_at' => now(),
                ]);

            $link->forceFill([
                'team_id' => $deal->team_id,
                'deal_id' => $deal->getKey(),
                'team_membership_id' => $membership->getKey(),
                'token_hash' => StatusPageLink::hashToken($token),
                'expires_at' => now()->addMinutes(StatusPageLink::LINK_MINUTES),
            ])->save();
        });

        $this->activity->record(
            subject: $deal,
            eventType: 'status_page.link_issued',
            summary: 'A status page link was sent to '.$membership->fullName(),
            source: ActivitySource::System,
            actor: $actor,
            payload: ['linkId' => $link->getKey()],
            teamId: $deal->team_id,
            deal: $deal,
        );

        $this->audit->record(
            action: 'status_page.link_issued',
            auditable: $link,
            teamId: $deal->team_id,
            actorPersonId: $actor?->getKey(),
            after: ['deal_id' => $deal->getKey(), 'team_membership_id' => $membership->getKey()],
        );

        return new IssuedLink($link, $token);
    }

    /**
     * Spend a link and mint the session it establishes.
     *
     * ## Claimed with a conditional UPDATE, not a read-then-write
     *
     * The `message_key` pattern, three tables along. A client on a slow phone
     * taps the link twice, or a mail scanner fetches it before they do — and
     * *"single use"* has to mean the second attempt loses, not that both
     * succeed because both read `used_at` as null. `WHERE used_at IS NULL` is
     * what makes it true.
     *
     * The loser of that race gets S64, which is the correct screen: their link
     * has been used. What they can do about it is on that screen.
     */
    public function redeem(StatusPageLink $link): ?string
    {
        $session = Str::random(43);

        $claimed = StatusPageLink::query()
            ->whereKey($link->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'used_at' => now(),
                'session_token_hash' => StatusPageLink::hashToken($session),
                'session_expires_at' => now()->addDays(StatusPageLink::SESSION_DAYS),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return null;
        }

        $link->refresh();

        $deal = $link->deal;

        if ($deal instanceof Deal) {
            $this->activity->record(
                subject: $deal,
                eventType: 'status_page.opened',
                summary: ($link->membership?->fullName() ?? 'A client').' opened the status page',
                source: ActivitySource::System,
                payload: ['linkId' => $link->getKey()],
                teamId: $link->team_id,
                deal: $deal,
            );
        }

        /*
         * PRD §9 puts *document access* and client reads in the audit log,
         * which is a different record from the timeline: different retention,
         * different readers, and append-only. The timeline entry above is what
         * an agent sees on the deal; this is what survives for a compliance
         * question.
         */
        $this->audit->record(
            action: 'status_page.opened',
            auditable: $link,
            teamId: $link->team_id,
            after: ['deal_id' => $link->deal_id],
        );

        return $session;
    }

    /**
     * Note that somebody looked, without writing an entry per page view.
     *
     * A client refreshing a page four times is one visit, and four timeline
     * entries would bury the advance that happened between them. The counter
     * and the timestamp are the operational record; the *first* open is the
     * one that got an entry, above.
     */
    public function touch(StatusPageLink $link): void
    {
        StatusPageLink::query()
            ->whereKey($link->getKey())
            ->update([
                'last_seen_at' => now(),
                'view_count' => DB::raw('view_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Take the access away, both credentials at once.
     */
    public function revoke(StatusPageLink $link, ?Person $actor = null): void
    {
        if ($link->isRevoked()) {
            return;
        }

        $link->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor?->getKey(),
        ])->save();

        $deal = $link->deal;

        if ($deal instanceof Deal) {
            $this->activity->record(
                subject: $deal,
                eventType: 'status_page.link_revoked',
                summary: 'Status page access was revoked for '
                    .($link->membership?->fullName() ?? 'a client'),
                source: ActivitySource::System,
                actor: $actor,
                payload: ['linkId' => $link->getKey()],
                teamId: $link->team_id,
                deal: $deal,
            );
        }

        $this->audit->record(
            action: 'status_page.link_revoked',
            auditable: $link,
            teamId: $link->team_id,
            actorPersonId: $actor?->getKey(),
            after: ['deal_id' => $link->deal_id],
        );
    }

    /**
     * The link a token names, whatever state it is in.
     *
     * Returns the row even when it is spent or revoked, because S64 has to say
     * **which** — *"already used"* and *"expired"* are different sentences to
     * a client, and a lookup that filtered them out could only say "invalid".
     *
     * `withoutTeamScope()` because a client has no team resolved: the token is
     * what establishes the tenant (ADR 0002's stated exception, the same one
     * an invitation makes).
     */
    public function findByLinkToken(string $token): ?StatusPageLink
    {
        return StatusPageLink::withoutTeamScope()
            ->where('token_hash', StatusPageLink::hashToken($token))
            ->first();
    }

    public function findBySessionToken(string $token): ?StatusPageLink
    {
        return StatusPageLink::withoutTeamScope()
            ->where('session_token_hash', StatusPageLink::hashToken($token))
            ->first();
    }
}
