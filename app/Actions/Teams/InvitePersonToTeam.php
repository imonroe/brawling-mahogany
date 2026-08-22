<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Mail\TeamInvitationMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Invite somebody into a team (PRD §4.1 F1.3 · Screen Inventory S74, S90).
 *
 * The plaintext token is returned rather than stored: this method is the only
 * place it exists, and the mail is sent from inside the transaction's
 * `afterCommit` so a rolled-back invitation never produces a live link.
 */
final class InvitePersonToTeam
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Team $team, string $email, Role $role, ?Person $invitedBy = null, ?string $firstName = null, ?string $lastName = null): TeamInvitation
    {
        return DB::transaction(function () use ($team, $email, $role, $invitedBy, $firstName, $lastName): TeamInvitation {
            $token = TeamInvitation::newToken();

            $invitation = TeamInvitation::query()->create([
                'team_id' => $team->getKey(),
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role_id' => $role->getKey(),
                'invited_by_person_id' => $invitedBy?->getKey(),
                'token_hash' => TeamInvitation::hashToken($token),
                'expires_at' => now()->addDays(TeamInvitation::LIFETIME_DAYS),
            ]);

            $this->audit->record(
                action: 'invitation.sent',
                auditable: $invitation,
                teamId: $team->getKey(),
                actorPersonId: $invitedBy?->getKey(),
                after: ['role' => $role->key],
            );

            DB::afterCommit(fn () => Mail::to($email)->send(new TeamInvitationMail($invitation, $token)));

            return $invitation;
        });
    }
}
