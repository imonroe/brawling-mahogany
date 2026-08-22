<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Teams\IssueInvitationLink as IssueLink;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;

/**
 * Print the accept link for an outstanding invitation (ADR 0003).
 *
 * ## The gap this closes
 *
 * `platform:promote` fixed the bootstrap for the *first administrator*. The
 * step immediately after it had the same shape and no answer: the
 * administrator provisions a team, the team's owner is invited, and the
 * invitation is delivered by an email that — on a machine with no mail
 * transport, which is every fresh local environment — is never sent. The
 * product's own first customer was unreachable from inside it.
 *
 * The screens (S74, S83) are the ordinary answer and cover the running
 * install. This is the one for the install that is not running yet: no
 * administrator, no team, nobody who can open a screen at all.
 *
 * ## Why the console is the right home
 *
 * The same argument `platform:promote` makes. It needs shell access to the
 * server, which is the same bar as reading the database directly, and unlike
 * editing a row by hand it leaves an audit entry.
 */
class IssueInvitationLink extends Command
{
    use ConfirmableTrait;

    protected $signature = 'invitation:link
                            {email : The invited address}
                            {--team= : The team slug or id, when the address is invited to more than one}
                            {--force : Skip the confirmation prompt in production}';

    protected $description = 'Print the accept link for an outstanding team invitation.';

    public function handle(IssueLink $issue, TeamContext $teams): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        $reference = $this->option('team');
        $team = is_string($reference) && trim($reference) !== ''
            ? Team::query()->where('slug', trim($reference))->orWhere('id', trim($reference))->first()
            : null;

        if (is_string($reference) && trim($reference) !== '' && ! $team instanceof Team) {
            $this->components->error("No team matches [{$reference}].");

            return self::FAILURE;
        }

        $invitations = $this->outstandingFor($email, $team);

        if ($invitations->isEmpty()) {
            $this->components->error("No live invitation is outstanding for [{$email}].");

            /*
             * The three ways an invitation stops being live all look
             * identical from here — spent, revoked, expired — and the remedy
             * is the same for all three, so it is worth saying rather than
             * making somebody query the table to find out which.
             */
            $this->components->info(
                'An invitation that has been accepted, revoked, or has expired will not appear here. '
                .'Send a new one from Settings → Members, or from /admin when the team has no owner yet.',
            );

            return self::FAILURE;
        }

        if ($invitations->count() > 1) {
            $this->components->error("[{$email}] is invited to more than one team. Name one with --team.");

            $this->table(
                ['Team', 'Slug', 'Role', 'Expires'],
                $invitations->map(fn (TeamInvitation $invitation): array => [
                    $invitation->team->name,
                    $invitation->team->slug,
                    $invitation->role->name,
                    $invitation->expires_at->toDateTimeString(),
                ])->all(),
            );

            return self::FAILURE;
        }

        $invitation = $invitations->sole();
        $invitedTeam = $invitation->team;

        // Not a refusal: an operator issuing a link while a team is suspended
        // is plausibly about to restore it. But nobody can sign in to a
        // suspended team, so the link would look broken without this line.
        if ($invitedTeam->suspended_at !== null) {
            $this->components->warn(
                "{$invitedTeam->name} is suspended. Nobody can sign in to it until it is restored, "
                .'so this link will not work yet.',
            );
        }

        /*
         * Inside the invitation's team, and not because anything here needs a
         * scope: `IssueInvitationLink` writes the invitation, which is
         * `BelongsToTeam`, so its `updating` guard compares the resolved team
         * against the row's. A console run has no session and therefore no
         * resolved team, so this worked by the *absence* of an ambient
         * condition — which is exactly the shape of the bug ADR 0003 records
         * under "what the adversarial review caught", and it would throw the
         * day somebody calls this from a request.
         */
        $url = $teams->runFor($invitedTeam, fn (): string => $issue->handle(
            $invitation,
            // No actor: an operator with a shell is not a person the
            // application knows, and inventing one would be worse than a null.
            issuedBy: null,
            reason: 'Issued from the console by a server operator.',
        ));

        $this->components->info(
            "{$invitedTeam->name} · {$invitation->role->name} · expires {$invitation->expires_at->toDayDateTimeString()}",
        );

        $this->newLine();
        $this->line($url);
        $this->newLine();

        // Said plainly, because it is the surprising part: only the hash is
        // stored, so this command cannot show the same link twice.
        $this->components->warn(
            'This link is new, and it replaces any link already sent to this address. '
            .'It is not stored and cannot be shown again — run this command for another.',
        );

        return self::SUCCESS;
    }

    /**
     * Live invitations to an address, optionally narrowed to one team.
     *
     * Unscoped, and it has to be: a console run has no session and therefore
     * no resolved team, and the whole question is which team invited this
     * address. `--team` narrows it by hand, in the query, which is the only
     * shape a no-tenant context can use.
     *
     * @return Collection<int, TeamInvitation>
     */
    private function outstandingFor(string $email, ?Team $team): Collection
    {
        $query = TeamInvitation::withoutTeamScope()
            ->pending()
            ->whereRaw('lower(email) = ?', [$email])
            // A deleted team takes its invitations with it, and an invitation
            // pointing at nothing is not one anybody can accept. This is also
            // what lets everything downstream read `->team` without a guard.
            ->whereHas('team')
            ->with(['team', 'role:id,name']);

        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return $query->get();
    }
}
