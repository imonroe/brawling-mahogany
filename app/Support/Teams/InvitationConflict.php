<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\Person;
use App\Models\TeamMembership;

/**
 * The one address a team cannot invite, and the sentence that says why.
 *
 * ## What it detects
 *
 * Since #140 an address can exist in two places at once: on a **membership**
 * (a contact this team added, whose `people` row holds no credentials) and on
 * a **`people` row** (somebody who already signs in, here or in another team).
 * Both at once is the case that cannot be resolved automatically:
 *
 *  - Repointing the membership at the account breaks every activity event and
 *    contact-log entry that names the person it currently holds.
 *  - Attaching a second membership for the account collides with
 *    `team_memberships_team_email_unique`.
 *
 * ## Why it is asked twice
 *
 * At **invite** time, so the refusal reaches the person who can act on it —
 * the team member typing the address, who can remove the duplicate contact.
 * Round 2 caught the first attempt at this: the check lived only in
 * `AcceptInvitation`, so the invitation sent cleanly, and the *invitee* got a
 * validation error on a screen with no field to render it. Silent for
 * everybody, forever.
 *
 * And at **accept** time, because an invitation is a link somebody holds for
 * days: the directory entry can be added after the invitation goes out, and a
 * check that only ran at the start would be a check that stopped being true.
 */
final class InvitationConflict
{
    /**
     * The reason this address cannot be invited into this team, or null.
     *
     * Deliberately a sentence rather than a boolean or an enum. There is one
     * conflict, it needs explaining rather than naming, and both callers show
     * the same words — a `ValidationException` on the members screen and on
     * the accept screen.
     */
    public static function reasonFor(string $teamId, string $email): ?string
    {
        $address = mb_strtolower(trim($email));

        if ($address === '') {
            return null;
        }

        $membership = TeamMembership::withoutTeamScope()
            ->where('team_id', $teamId)
            ->whereRaw('lower(email) = ?', [$address])
            ->whereNull('revoked_at')
            ->first();

        if (! $membership instanceof TeamMembership) {
            return null;
        }

        // Already an account *and* already in the directory as somebody else.
        // A contact who simply has no login is the ordinary case, and the
        // whole point of inviting them.
        if ($membership->person->hasCredentials()) {
            return null;
        }

        $account = Person::query()->whereRaw('lower(email) = ?', [$address])->first();

        if (! $account instanceof Person) {
            return null;
        }

        return 'Somebody already signs in with this address, and this team also has them '
            .'as a contact. Remove the contact from your people directory first, then send '
            .'the invitation again.';
    }
}
