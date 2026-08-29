<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Support\Audit\AuditLogger;
use App\Support\Extraction\Money;
use App\Support\Extraction\SpendLedger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;

/**
 * The writer for `teams.extraction_monthly_cap_micros` (#113 · PRD §14.3).
 *
 * ## A reader with no writer is as dead as a row nothing can reach
 *
 * CLAUDE.md's own finding, recorded about `teams.logo_path` and repeated here:
 * the column shipped with `SpendLedger::capFor()` reading it, a CHECK
 * constraint guarding it, tests exercising it — and nothing anywhere in the
 * application able to set it. The refusal a team met when they hit the ceiling
 * even told them *"an owner can raise it in Settings"*, which was a control
 * that did not exist on a screen that did not have it.
 *
 * ## Why it is not that screen
 *
 * `SpendLedger` calls this ceiling *"a commercial limit"*, and the migration
 * describes the column as what an operator sets *"for the one team that needs
 * stopping now"*. A commercial limit the customer can raise for themselves is
 * not a limit, and a ceiling somebody puts on a team that is spending too fast
 * is precisely the one that team must not be able to lift.
 *
 * So it goes where `mail:suppression` goes, for the reason stated there: some
 * decisions are *"deliberately not something a team can do to itself"*. The
 * refusal now names only what the reader can actually do — wait for the month,
 * or ask whoever runs the installation — and this is that person's door.
 *
 * `/admin` would be the other candidate and can replace this without changing
 * the semantics; a console command with an audit entry satisfies the
 * requirement now.
 *
 * ## Dollars in, micros stored
 *
 * The column is micros because `extractions.cost_micros` is, and nobody types
 * `50000000` meaning fifty dollars without eventually typing one nought too
 * many. `--dollars` is the only way in, `Money::fromDollars()` is the only
 * conversion, and the confirmation prints the figure back in words so a
 * mistyped one is visible before it is a ceiling.
 */
class SetExtractionCap extends Command
{
    protected $signature = 'extraction:cap
        {team : The team, by slug or id}
        {--dollars= : The new monthly ceiling, in dollars. 0 stops the team now}
        {--clear : Remove the override, so the team follows the configured default}';

    protected $description = 'Show or set one team’s monthly extraction ceiling (audited)';

    public function handle(SpendLedger $ledger, AuditLogger $audit, TeamContext $teams): int
    {
        $team = $this->resolveTeam((string) $this->argument('team'));

        if (! $team instanceof Team) {
            return self::FAILURE;
        }

        $dollars = $this->option('dollars');
        $clearing = (bool) $this->option('clear');

        if ($clearing && $dollars !== null) {
            $this->components->error('Pass either --dollars or --clear, not both.');

            return self::FAILURE;
        }

        if (! $clearing && $dollars === null) {
            return $this->report($team, $ledger, $teams);
        }

        $before = $team->extraction_monthly_cap_micros;

        if ($clearing) {
            $after = null;
        } else {
            if (! is_numeric($dollars) || (float) $dollars < 0) {
                $this->components->error('--dollars must be a number, and not a negative one.');

                return self::FAILURE;
            }

            $after = Money::fromDollars((float) $dollars);
        }

        $team->forceFill(['extraction_monthly_cap_micros' => $after])->save();

        /*
         * `teamId` is the team the ceiling was put on, so the entry lands in
         * that team's own log rather than nowhere: PRD §9 wants a team able to
         * see what was done to it, and *"an operator stopped your extraction"*
         * is exactly the kind of thing a support conversation later turns on.
         *
         * `actorPersonId` is null because a console operator has no membership
         * and often no account at all — `platform:promote` records it the same
         * way, and the reason is the honest one: nobody signed in.
         */
        $teams->runFor($team, fn () => $audit->record(
            action: 'extraction.cap_changed',
            auditable: $team,
            teamId: $team->getKey(),
            actorPersonId: null,
            reason: 'Set from the console by a server operator.',
            before: ['extraction_monthly_cap_micros' => $before],
            after: ['extraction_monthly_cap_micros' => $after],
        ));

        $this->components->info(
            $after === null
                ? "{$team->name} now follows the configured default."
                : "{$team->name} may spend ".Money::words($after).' a month on extraction.',
        );

        return $this->report($team->refresh(), $ledger, $teams);
    }

    private function resolveTeam(string $subject): ?Team
    {
        /*
         * Slug or id, told apart by asking for both rather than by guessing at
         * the shape. A slug is free text and a ULID is not, but a team slugged
         * `01jd…` is a valid slug and refusing it would be a rule nobody could
         * see.
         */
        $team = Team::query()
            ->where('slug', $subject)
            ->orWhere('id', $subject)
            ->first();

        if (! $team instanceof Team) {
            $this->components->error("No team matches [{$subject}].");
        }

        return $team;
    }

    private function report(Team $team, SpendLedger $ledger, TeamContext $teams): int
    {
        $cap = $ledger->capFor($team);

        /*
         * Inside the team's own context, because `teamSpentThisMonth()` reads
         * `extractions` through the global scope and a console run has resolved
         * no tenant — `TeamScope` throws rather than answering unscoped, which
         * is ADR 0002 working and not an obstacle to route around with
         * `withoutTeamScope()`.
         */
        $spent = $teams->runFor($team, fn (): int => $ledger->teamSpentThisMonth($team));

        $this->components->twoColumnDetail('Team', $team->name);
        $this->components->twoColumnDetail(
            'Ceiling',
            Money::words($cap).($team->extraction_monthly_cap_micros === null ? ' (the configured default)' : ' (set for this team)'),
        );
        $this->components->twoColumnDetail('Spent this month', Money::words($spent));
        $this->components->twoColumnDetail('Resets', $ledger->resetsAt()->toDayDateTimeString().' UTC');

        return self::SUCCESS;
    }
}
