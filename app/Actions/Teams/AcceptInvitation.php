<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Turn an invitation into a membership (Screen Inventory S04).
 *
 * The case that makes this more than an insert: *"Accepting an invitation for
 * an email that already has a `people` record attaches a new
 * `team_membership` rather than creating a second person."* That is the
 * shared-person decision (#18) doing its job — the stager who already works
 * for another team keeps one record and one phone number.
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
            $person = Person::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])
                ->first();

            $alreadyHadCredentials = $person instanceof Person && $person->hasCredentials();

            if ($person instanceof Person) {
                // An existing person keeps their name and their record. The
                // only thing an invitation may add is credentials, and only
                // when they had none.
                if (! $alreadyHadCredentials) {
                    $person->forceFill([
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

            $this->teams->runFor($team, function () use ($invitation, $person, $firstName, $lastName): void {
                // The name goes in the insert, not a follow-up write:
                // `first_name` is not nullable, so a membership cannot exist
                // for a moment without one.
                $membership = TeamMembership::query()->firstOrCreate(
                    ['team_id' => $invitation->team_id, 'person_id' => $person->getKey()],
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName ?? $invitation->last_name,
                        'email' => $invitation->email,
                        'status' => PersonLifecycleState::Active,
                        'joined_at' => now(),
                    ],
                );

                /*
                 * The name the invitee typed, or the one the inviter typed for
                 * them, on this team's row only. Somebody who already works in
                 * another team keeps whatever that team calls them — a second
                 * team is not entitled to rename them anywhere but here.
                 *
                 * `firstOrCreate` may have just made the row, and a membership
                 * with no name renders as a blank line, so a fallback is
                 * needed: the address before the @, which is the least-bad
                 * thing anybody knows.
                 */
                // The invitee types their own name on the accept screen, and
                // it is required there — so it always wins. Somebody who
                // already works in another team keeps whatever that team
                // calls them: a second team may name them here and nowhere
                // else.
                $membership->forceFill([
                    'first_name' => $firstName,
                    'last_name' => $lastName ?? $invitation->last_name ?? $membership->last_name,
                    'email' => $membership->email ?? $invitation->email,
                    'revoked_at' => null,
                    'joined_at' => $membership->joined_at ?? now(),
                ])->save();

                $membership->roles()->syncWithoutDetaching([$invitation->role_id]);
            });

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record(
                action: 'invitation.accepted',
                auditable: $invitation,
                teamId: $invitation->team_id,
                actorPersonId: $person->getKey(),
            );

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
}
