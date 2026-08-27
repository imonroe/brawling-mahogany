<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\StatusPageLink;
use App\Models\TeamMembership;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Print a status page link for a client (ADR 0003 · issue #110).
 *
 * ## The gap this closes
 *
 * *"No user flow depends on email alone."* The ordinary answer for a status
 * page link is the deal's People tab, where an agent can send it **or** copy
 * it — that is already two doors, and it covers the running install.
 *
 * This is the third, and it is for the case the screens cannot serve: an
 * install with no mail transport at all, where the team's own owner has not
 * finished signing in, or a support conversation where somebody has to read a
 * URL down the phone and cannot reach the app to get one.
 *
 * The same bar as `invitation:link` and for the same reason: it needs shell
 * access to the server, which is the bar for reading the database directly,
 * and unlike editing a row by hand it leaves an audit entry.
 *
 * ## It names a deal and a person, and refuses to guess
 *
 * A client on two deals with this team has two status pages, and a command
 * that picked one would hand somebody the wrong transaction. So both are
 * arguments, and an ambiguous address is an error with the candidates printed
 * rather than a choice made for the operator.
 */
class IssueStatusPageLinkCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'status-page:link
                            {deal : The deal id}
                            {email : The client’s address, as the team recorded it}
                            {--force : Skip the confirmation prompt in production}';

    protected $description = 'Print a status page link for a client on a deal.';

    public function handle(IssueStatusPageLink $links, TeamContext $teams): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $deal = Deal::withoutTeamScope()
            ->whereKey(trim((string) $this->argument('deal')))
            ->with('team')
            ->first();

        if (! $deal instanceof Deal) {
            $this->components->error('No deal matches ['.$this->argument('deal').'].');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        $membership = TeamMembership::withoutTeamScope()
            ->where('team_id', $deal->team_id)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if (! $membership instanceof TeamMembership) {
            $this->components->error("No person in that team has the address [{$email}].");

            /*
             * The likely cause, said rather than left to be discovered: a
             * client's address lives on the **membership** (Slice 1 moved
             * contact details there), so an address that signs in is not
             * necessarily one this team recorded.
             */
            $this->components->info(
                'The address is the one the team recorded for them on the People screen, '
                .'which is not necessarily an address they sign in with.',
            );

            return self::FAILURE;
        }

        $issued = $teams->runFor(
            $deal->team,
            fn () => $links->issue($deal, $membership),
        );

        $this->components->info('Status page link for '.$membership->fullName().':');
        $this->line($issued->url());
        $this->components->warn(
            'It works once, for '.StatusPageLink::LINK_MINUTES.' minutes, and issuing it '
            .'revoked any link this person already had for this deal.',
        );

        return self::SUCCESS;
    }
}
