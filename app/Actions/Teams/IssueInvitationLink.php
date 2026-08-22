<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Person;
use App\Models\TeamInvitation;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hand the invitation link to somebody who may already send it (ADR 0003).
 *
 * ## Why this is not a new privilege
 *
 * Whoever can call this can already invite the address, revoke the
 * invitation, and re-invite it — they own the invitation completely. Giving
 * them the link lets them deliver it over a channel they choose (a text
 * message, a call, a person standing next to them) instead of the one
 * channel the product happens to own. It grants nothing that inviting again
 * would not.
 *
 * What it deliberately does *not* do is let anybody read a link back. Only
 * the SHA-256 hash is stored, on purpose (`TeamInvitation`), so there is
 * nothing to read — a plaintext token exists for the length of one request
 * and then only in the hands of whoever asked for it.
 *
 * ## Rotation, and the cost of it
 *
 * Because nothing is stored, issuing a link **mints a new one and replaces
 * the old**. Any link already emailed stops working. That is a real cost and
 * the screens say so out loud; the alternative is storing a recoverable
 * credential, which is the thing the hash exists to avoid.
 *
 * The expiry is not extended. An invitation with two days left issues a link
 * with two days on it — a link that renewed the clock would make "revoke and
 * send a new one" a formality rather than a decision.
 */
final class IssueInvitationLink
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string|null  $reason  Recorded on the audit entry. The console
     *                               passes one because there is no actor to
     *                               identify; a signed-in issuer is their own.
     * @return string The accept URL, which exists nowhere else.
     */
    public function handle(TeamInvitation $invitation, ?Person $issuedBy = null, ?string $reason = null): string
    {
        return DB::transaction(function () use ($invitation, $issuedBy, $reason): string {
            $token = TeamInvitation::newToken();

            // `token_hash` is not fillable — an invitation link is a
            // credential, and a credential is never settable from a request
            // body. This and InvitePersonToTeam are the only writers.
            $invitation->forceFill(['token_hash' => TeamInvitation::hashToken($token)])->save();

            /*
             * PRD §9 audits permission changes, and issuing a working link to
             * a role in a team is one. The address is not recorded: it is on
             * the invitation this entry already points at, and PRD §9 is
             * explicit that no PII goes in the log.
             */
            $this->audit->record(
                action: 'invitation.link_issued',
                auditable: $invitation,
                teamId: $invitation->team_id,
                actorPersonId: $issuedBy?->getKey(),
                reason: $reason,
            );

            return route('invitations.show', ['token' => $token]);
        });
    }
}
