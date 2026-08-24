<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Person;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Teams\InvitationConflict;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turn an invitation into a membership (Screen Inventory S04, S09).
 *
 * The case that makes this more than an insert: *"Accepting an invitation for
 * an email that already has a `people` record attaches a new
 * `team_membership` rather than creating a second person."* That is the
 * shared-person decision (#18) doing its job — the stager who already works
 * for another team keeps one record and one phone number.
 *
 * ## Two ways in, one outcome
 *
 * `handle()` is the emailed link (S04): somebody holding a token, who may
 * have no account at all, so it takes a name and a password.
 *
 * `claim()` is the in-app answer (ADR 0003, S09): somebody already signed in
 * whose sign-in address *is* the invited address. It takes neither — they
 * have credentials already, and this must never touch them.
 *
 * Both end in the same two steps, which is why those steps are shared rather
 * than written twice: attach the membership, then mark the invitation
 * accepted and audit it.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly TeamContext $teams,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{person: Person, mayAuthenticate: bool}
     */
    public function handle(TeamInvitation $invitation, string $firstName, ?string $lastName, string $password): array
    {
        return DB::transaction(function () use ($invitation, $firstName, $lastName, $password): array {
            $team = $invitation->team()->sole();

            // Case-insensitively, because `Emily@Example.test` and
            // `emily@example.test` are one human (PRD decision log,
            // 2026-08-22) and the unique index says so too.
            $address = mb_strtolower($invitation->email);

            /*
             * Two places somebody with this address might already exist, and
             * before #140 there was only one.
             *
             * A **directory entry** — a client this team added, who holds a
             * membership carrying the address and a credential-less `people`
             * row whose own `email` is null. Inviting one is the ordinary case
             * of promoting a client to the status page, and looking only at
             * `people` missed it entirely: a second person was created, a
             * second membership inserted, and the partial unique index on
             * `(team_id, lower(email))` refused it. A 500 on accept, and no
             * way to fix it from inside the product.
             *
             * An **account** — somebody who already signs in, here or in
             * another team. That is the case issue #45 is about, and it still
             * works the way it did: one login, a second membership.
             */
            $membership = TeamMembership::withoutTeamScope()
                ->where('team_id', $invitation->team_id)
                ->whereRaw('lower(email) = ?', [$address])
                ->whereNull('revoked_at')
                ->first();

            $account = Person::query()->whereRaw('lower(email) = ?', [$address])->first();

            $person = $membership instanceof TeamMembership ? $membership->person : $account;

            $alreadyHadCredentials = $person instanceof Person && $person->hasCredentials();

            $this->guardAgainstConflict($invitation);

            if ($person instanceof Person) {
                // An existing person keeps their record. The only thing an
                // invitation may add is credentials, and only when they had
                // none — which is what turns a directory entry into an
                // account without duplicating the human.
                if (! $alreadyHadCredentials) {
                    $person->forceFill([
                        'email' => $invitation->email,
                        'password' => $password,
                        'email_verified_at' => now(),
                    ])->save();
                }
            } else {
                // The login, and nothing else. Their name belongs to the team
                // they are joining (#140), so it goes on the membership below.
                $person = Person::query()->create([
                    'email' => $invitation->email,
                    'password' => $password,
                ]);

                $person->forceFill(['email_verified_at' => now()])->save();
            }

            /*
             * Both writes inside one `runFor`, and the invitation write is
             * the reason.
             *
             * `finalise()` saves the `TeamInvitation`, which is
             * `BelongsToTeam` — so its `updating` hook asks whether the
             * resolved team matches the row's, and throws when it does not.
             * `ResolveCurrentTeam` is global middleware, so a signed-in
             * member of another team has one resolved on this request.
             * Attaching inside the team and then spending the invitation
             * outside it is exactly the mistake ProfileController documents.
             */
            $this->teams->runFor($team, function () use ($invitation, $person, $firstName, $lastName): void {
                $this->attachMembership(
                    $invitation,
                    $person,
                    $firstName,
                    $lastName ?? $invitation->last_name,
                    // The invitee typed this on the accept screen, where it is
                    // required. It wins over anything the team recorded.
                    nameIsAuthoritative: true,
                );

                $this->finalise($invitation, $person);
            });

            /*
             * **An invitation is not a way into an existing account.**
             *
             * When the address already had a password, whoever is holding
             * this link has proved only that they hold the link. Signing them
             * in would make an emailed URL a working credential for somebody
             * else's account, silently — the password is not even changed, so
             * the owner would have nothing to notice.
             *
             * The membership is still attached: the team owner decided that,
             * and it costs the invitee nothing. They sign in as themselves.
             */
            return ['person' => $person, 'mayAuthenticate' => ! $alreadyHadCredentials];
        });
    }

    /**
     * Accept from inside the product, with no token (ADR 0003 · S09).
     *
     * The caller has already established that `$person` is signed in and that
     * their sign-in address is the invited one — `PendingInvitations` is the
     * only thing that answers that question, and it explains there why a
     * matched session is not weaker than a matched token.
     *
     * Note what is missing compared with `handle()`: no password, no
     * `email_verified_at`, no account creation, no branch on whether
     * credentials existed. A claim can add a membership and nothing else,
     * which is what makes it safe to offer without a token.
     */
    public function claim(TeamInvitation $invitation, Person $person): TeamMembership
    {
        return DB::transaction(fn (): TeamMembership => $this->teams->runFor(
            $invitation->team()->sole(),
            function () use ($invitation, $person): TeamMembership {
                $this->guardAgainstConflict($invitation);

                /*
                 * Nobody typed a name here — that is the whole point of the
                 * screen, one button — so the invitation's is used, and
                 * failing that the address before the @. The same placeholder
                 * `ProvisionTeam::attachOwner` uses, changeable from their
                 * profile, and better than a blank line on the members screen.
                 *
                 * `nameIsAuthoritative: false` because of that: a placeholder
                 * nobody chose must never overwrite a name a team typed.
                 */
                $membership = $this->attachMembership(
                    $invitation,
                    $person,
                    $invitation->first_name ?? Str::before((string) $person->email, '@'),
                    $invitation->last_name,
                    nameIsAuthoritative: false,
                );

                $this->finalise($invitation, $person, 'Accepted in the application by the invited account.');

                return $membership;
            },
        ));
    }

    /**
     * The directory-entry collision, asked at accept time.
     *
     * `MemberController::invite` asks it first, so an invitation that would
     * land here mostly never gets sent — which matters, because the person who
     * can fix it is the one typing the address, not the one holding the link.
     * This is the second ask, because a contact can be added to the directory
     * *after* the invitation goes out and a check that only ran at the start
     * would be a check that stopped being true.
     */
    private function guardAgainstConflict(TeamInvitation $invitation): void
    {
        $conflict = InvitationConflict::reasonFor($invitation->team_id, mb_strtolower($invitation->email));

        if ($conflict !== null) {
            throw ValidationException::withMessages(['email' => $conflict]);
        }
    }

    /**
     * The membership, and the role that came with the invitation.
     *
     * **Must be called inside `runFor($invitation->team)`.** It writes two
     * team-scoped models, and `BelongsToTeam` refuses a write aimed at a team
     * other than the resolved one — which is a feature, and the reason both
     * callers resolve the team themselves rather than leaving it to chance.
     *
     * @param  bool  $nameIsAuthoritative  Whether `$firstName` came from a
     *                                     human who typed it for this team.
     */
    private function attachMembership(
        TeamInvitation $invitation,
        Person $person,
        string $firstName,
        ?string $lastName,
        bool $nameIsAuthoritative,
    ): TeamMembership {
        // The name goes in the insert, not a follow-up write: `first_name` is
        // not nullable, so a membership cannot exist for a moment without one.
        $membership = TeamMembership::query()->firstOrCreate(
            ['team_id' => $invitation->team_id, 'person_id' => $person->getKey()],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $invitation->email,
                'joined_at' => now(),
            ],
        );

        /*
         * **A revived membership does not get its old roles back.**
         *
         * `TeamMembership::revoke()` writes `revoked_at` and nothing else —
         * deliberately, because PRD F1.3 wants historical attribution to
         * survive — and nothing anywhere detaches roles. `hasPermission()`
         * short-circuits on `isRevoked()`, so the roles are *dormant, not
         * gone*, and they return the instant `revoked_at` is null.
         *
         * `firstOrCreate` finds the revoked row (the unique index is partial
         * on `deleted_at`, not on `revoked_at`), so without this a team that
         * revoked an owner and later re-invited them as a Team Member got an
         * owner back: `syncWithoutDetaching` added the new role *on top of*
         * the old one, and the audit log recorded `invitation.accepted`
         * rather than a permission grant.
         *
         * So for a revival the invitation defines the whole role set. The
         * team decided to bring them back **as** that role, and anything else
         * is a grant nobody made. Captured before the `forceFill` below,
         * which is what clears the flag.
         */
        $wasRevoked = $membership->isRevoked();

        /*
         * Who gets to name somebody, when a membership already exists.
         *
         * On the accept screen the invitee types their own name and it is
         * required, so it wins — somebody who already works in another team
         * keeps whatever that team calls them, and a second team may name
         * them here and nowhere else.
         *
         * A claim types nothing. The name is the invitation's, or the address
         * before the @, and neither is a name anybody chose. Letting that win
         * turned *Heather Cole* into *heather Cole* on the ordinary
         * revoke-then-re-invite path — silently, on the one field #140 moved
         * onto the membership precisely so the team would own it. So when the
         * name is not authoritative, what the team already recorded stands.
         */
        /*
         * Joining **the team** ends the contact lifecycle (#162).
         *
         * `firstOrCreate` reuses an existing row, and the insert half never
         * runs for one — so inviting somebody already in the directory carried
         * their `lead` or `active` forward onto a colleague, where IA §8 has
         * nothing to say about them. It stayed hidden while they held access
         * and reappeared the day it was revoked, as a blue **Lead** pill on
         * somebody who had worked here. Null is what a colleague's lifecycle
         * is; if they leave, the team records what they are then.
         *
         * **Only for a role that grants team access.** The members screen also
         * invites `Contact` and `Status Viewer` — roles a *client* holds — and
         * clearing the lifecycle for those erased a typed **Client** the
         * moment somebody was given a status-page login, leaving a row that
         * said nothing at all about a person the team had classified. Review
         * on #162 measured it through the routes.
         */
        $joinsTheTeam = $invitation->role->grantsTeamAccess();

        $membership->forceFill([
            'status' => $joinsTheTeam ? null : $membership->status,
            'first_name' => $nameIsAuthoritative || $membership->first_name === ''
                ? $firstName
                : $membership->first_name,
            'last_name' => $nameIsAuthoritative
                ? ($lastName ?? $membership->last_name)
                : ($membership->last_name ?? $lastName),
            'email' => $membership->email ?? $invitation->email,
            'revoked_at' => null,
            'joined_at' => $membership->joined_at ?? now(),
        ])->save();

        if (! $wasRevoked) {
            $membership->roles()->syncWithoutDetaching([$invitation->role_id]);

            return $membership;
        }

        /*
         * `withTrashed()`, because `sync()` deletes the pivot row for a role
         * the team has since retired just as readily as for a live one — and
         * a log that omits it understates what was taken away. `Role` soft
         * deletes (`HasProductDefaults`), so a plain read would not see it.
         */
        $roleKeys = fn (): array => $membership->roles()
            ->withTrashed()
            ->pluck('key')
            ->sort()
            ->values()
            ->all();

        $before = $roleKeys();

        $membership->roles()->sync([$invitation->role_id]);

        $after = $roleKeys();

        /*
         * PRD §9 audits permission changes, and this is the one place in the
         * product that *removes* a role. `invitation.accepted` says an
         * invitation was accepted; it does not say that somebody stopped
         * being a Team Owner, and the difference matters to whoever reads the
         * log later. Role keys, not names — reference data, never PII.
         *
         * Two consequences of reading through `withTrashed()`, both accepted:
         *
         *  - It can fire when no *effective* permission moved, because
         *    `permissionKeys()` reads the soft-delete-scoped relation and an
         *    archived role already granted nothing. Recording it anyway is
         *    the safer half: restoring that role later would otherwise
         *    silently fail to restore its member.
         *  - A key can appear twice. The unique indexes on `roles` are
         *    partial on `deleted_at`, so an archived role and a live one may
         *    share a key, and the log says two pivot rows went rather than
         *    collapsing them into one.
         */
        if ($before !== $after) {
            $this->audit->record(
                action: 'membership.roles_replaced',
                auditable: $membership,
                teamId: $invitation->team_id,
                actorPersonId: $person->getKey(),
                reason: 'A revoked membership was revived by an invitation, which defines its whole role set.',
                before: ['roles' => $before],
                after: ['roles' => $after],
            );
        }

        return $membership;
    }

    /**
     * Spend the invitation, once, and say so in the audit log.
     *
     * **Must be called inside `runFor($invitation->team)`**, for the same
     * reason `attachMembership` must: this writes the invitation, which is
     * team-scoped, and the `updating` guard throws when the resolved team is
     * somebody else's.
     */
    private function finalise(TeamInvitation $invitation, Person $person, ?string $reason = null): void
    {
        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->audit->record(
            action: 'invitation.accepted',
            auditable: $invitation,
            teamId: $invitation->team_id,
            actorPersonId: $person->getKey(),
            reason: $reason,
        );
    }
}
