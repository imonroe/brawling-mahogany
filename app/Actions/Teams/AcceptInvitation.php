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
                $person = Person::query()->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $invitation->email,
                    'password' => $password,
                ]);

                $person->forceFill(['email_verified_at' => now()])->save();
            }

            $this->teams->runFor($team, function () use ($invitation, $person): void {
                $membership = TeamMembership::query()->firstOrCreate(
                    ['team_id' => $invitation->team_id, 'person_id' => $person->getKey()],
                    ['status' => PersonLifecycleState::Active, 'joined_at' => now()],
                );

                $membership->forceFill(['revoked_at' => null, 'joined_at' => $membership->joined_at ?? now()])->save();
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
