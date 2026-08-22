<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\Person;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Collection;

/**
 * The invitations waiting for whoever is signed in (ADR 0003 · S09).
 *
 * ## Why this exists
 *
 * Until this, an invitation could only be answered by clicking a link in an
 * email. That is one channel, and a channel the product does not control: no
 * transport configured, a message in a spam folder, a shared mailbox nobody
 * watches, or a pre-production environment where mail deliberately goes
 * nowhere — and the invitation is unreachable, permanently, with no screen
 * anywhere that admits it exists. ADR 0003 makes that a defect rather than an
 * operational quirk.
 *
 * ## Why matching on the address is not weaker than the link
 *
 * The instinct is that a token proves something a session does not. Follow
 * what the emailed token actually does when the address already has an
 * account: `AcceptInvitation` attaches the membership **to that account** and
 * refuses to sign anybody in. So the link never was a way *into* an account —
 * it is a way of attaching a membership to whichever account holds the
 * invited address, which is precisely what this does, for somebody who has
 * already proved they hold it by signing in.
 *
 * The claim is in fact the weaker of the two: it can only ever add a
 * membership. It cannot set a password, and it cannot create an account.
 */
final class PendingInvitations
{
    /**
     * Every live invitation addressed to this person, newest first.
     *
     * Unscoped, and it has to be: the whole point is a person with no
     * membership anywhere, so there is no resolved team to scope to and the
     * invitation is what would establish one. It is keyed on the sign-in
     * address instead, folded, which is the same comparison
     * `AcceptInvitation` and the unique index make.
     *
     * A null address is what marks a `people` row with no login at all (#140),
     * and one of those is never the authenticated person — but the guard is
     * free and the alternative is matching every credential-less contact
     * against every invitation to a blank address.
     *
     * @return Collection<int, TeamInvitation>
     */
    public static function for(?Person $person): Collection
    {
        $address = mb_strtolower(trim((string) $person?->email));

        if (! $person instanceof Person || $address === '') {
            return new Collection;
        }

        return TeamInvitation::withoutTeamScope()
            ->pending()
            ->whereRaw('lower(email) = ?', [$address])
            /*
             * A suspended team is not one anybody can act in —
             * `Person::activeTeams()` excludes it from the switcher for the
             * same reason. Offering an invitation into one would take
             * somebody through an Accept button and land them back on the
             * screen they started from, which reads as a bug rather than as a
             * suspension. A soft-deleted team is excluded by the relation.
             */
            ->whereHas('team', fn ($query) => $query->whereNull('suspended_at'))
            ->with(['role:id,name', 'team:id,name'])
            ->latest()
            ->get();
    }

    /**
     * The same list, as the shell and S09 need it.
     *
     * Shaped here rather than in the middleware so that the query and the
     * props it produces stay next to each other — the banner and the "no
     * access" screen render from one source, and neither should be shaping a
     * model itself.
     *
     * @return list<array{id: string, teamName: string, role: string, expiresAt: string}>
     */
    public static function propsFor(?Person $person): array
    {
        $props = [];

        foreach (self::for($person) as $invitation) {
            $team = $invitation->team;

            // `whereHas` already required one, so this cannot happen — but a
            // banner reading "You've been invited to" with nothing after it
            // is worse than no banner, and the relation is nullable in type
            // whatever the query guarantees.
            if (! $team instanceof Team) {
                continue;
            }

            $props[] = [
                'id' => (string) $invitation->getKey(),
                'teamName' => $team->name,
                'role' => $invitation->role->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ];
        }

        return $props;
    }

    /**
     * One of them, by id, or null.
     *
     * Null rather than a distinguishable failure on every miss — a wrong id,
     * an expired invitation, and somebody else's invitation are one answer
     * here, because telling them apart would let a signed-in account probe
     * for live invitations belonging to other addresses.
     */
    public static function find(?Person $person, string $id): ?TeamInvitation
    {
        return self::for($person)->firstWhere('id', $id);
    }
}
