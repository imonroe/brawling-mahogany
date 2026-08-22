<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Cache;

/**
 * Make somebody a platform administrator (PRD §4.1 F1.5, §5.1 · issue #52).
 *
 * ## The bootstrap this closes
 *
 * PRD §5.1 step 1 is *"Ian provisions a team and invites the owner"*, and
 * `/admin` does exactly that — provisioning and inviting in one action. What
 * the product had no answer for is the step before it: **the first super
 * administrator.** `is_super_admin` is set nowhere in the UI, deliberately —
 * a screen that grants the highest privilege in the system is a screen worth
 * not having — so a fresh install had a console nobody could open, and a
 * registered account that could only be told to wait for an invitation that
 * nobody could send.
 *
 * The console is the right home for it. It needs shell access to the server,
 * which is the same bar as reading the database directly, and it leaves an
 * audit entry where editing a row by hand would not.
 *
 * ## What it deliberately does not do
 *
 * It does not create accounts. Somebody registers or is invited through the
 * ordinary path, and this promotes the account that results — so a password
 * is never typed on a command line, and never lands in a shell history.
 *
 * It also does not grant access to any *team*. A platform administrator runs
 * above the tenant boundary (ADR 0002) and holds no membership anywhere;
 * impersonation is how they see a customer's data, and it is logged with a
 * reason every time.
 */
class PromotePlatformAdministrator extends Command
{
    use ConfirmableTrait;

    protected $signature = 'platform:promote
                            {email : The sign-in address of an existing account}
                            {--demote : Take the privilege away instead}
                            {--demote-last : Allow demoting the only remaining administrator}
                            {--force : Skip the confirmation prompt in production}';

    protected $description = 'Grant or revoke platform administrator access for an existing account.';

    public function handle(AuditLogger $audit): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $demoting = (bool) $this->option('demote');

        $person = Person::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $person instanceof Person) {
            $this->components->error("No account signs in as [{$email}].");

            /*
             * The likeliest cause on a fresh install, said plainly. A contact
             * in a team's directory is not an account (#140), so an address
             * somebody typed into the people screen will not be found here —
             * and that is the confusing case worth naming.
             */
            $this->components->info(
                'Accounts are created by registering or by accepting an invitation. '
                .'A contact in a team directory is not an account and cannot be promoted.',
            );

            return self::FAILURE;
        }

        if ($person->is_super_admin === ! $demoting) {
            $this->components->info(
                $demoting
                    ? "[{$email}] is not a platform administrator. Nothing to do."
                    : "[{$email}] is already a platform administrator. Nothing to do.",
            );

            return self::SUCCESS;
        }

        if ($demoting && $this->isLastAdministrator($person)) {
            /*
             * A warning rather than a refusal, and the difference is that this
             * one is recoverable: the same command promotes somebody back.
             * The last-owner rule elsewhere is a refusal because a team with
             * no owner has no way back from inside the product at all.
             */
            $this->components->warn(
                "[{$email}] is the only platform administrator. "
                .'Nobody will be able to open /admin until this command is run again.',
            );

            /*
             * Its own flag, not `--force`.
             *
             * `--force` is `ConfirmableTrait`'s production gate, and it is
             * typed by every operator who runs anything in production. Letting
             * it double as the answer to this question means the one prompt
             * worth reading is the one nobody is ever asked.
             */
            if (! $this->option('demote-last') && ! $this->confirm('Demote them anyway?', false)) {
                return self::FAILURE;
            }
        }

        $person->forceFill(['is_super_admin' => ! $demoting])->save();

        // `/no-team` caches the answer to "does anybody administer this
        // platform" for a minute. This command is the only thing that changes
        // it, and the minute is exactly the minute somebody is staring at that
        // screen waiting for it to change.
        Cache::forget('platform.has-administrator');

        /*
         * PRD §9 requires permission changes to be audited, and this is the
         * largest one the product has. No team: the privilege spans all of
         * them. No actor: an operator with a shell is not a person the
         * application knows, and inventing one would be worse than a null.
         */
        $audit->record(
            action: $demoting ? 'platform.administrator_revoked' : 'platform.administrator_granted',
            auditable: $person,
            teamId: null,
            actorPersonId: null,
            reason: 'Granted from the console by a server operator.',
            after: ['is_super_admin' => ! $demoting],
        );

        if ($demoting) {
            $this->components->info("[{$email}] is no longer a platform administrator.");

            return self::SUCCESS;
        }

        $this->components->info("[{$email}] is now a platform administrator.");

        /*
         * PRD §9 makes a second factor mandatory for this role, so the next
         * sign-in lands on the enrolment screen rather than /admin. Worth
         * saying here: from the outside it looks like the promotion failed.
         */
        if ($person->two_factor_confirmed_at === null) {
            $this->components->warn(
                'Two-factor authentication is mandatory for this role. '
                .'They will be asked to enrol on their next sign-in before they can reach /admin.',
            );
        }

        $this->components->info('Next: open /admin to provision a team, which also invites its owner.');

        return self::SUCCESS;
    }

    private function isLastAdministrator(Person $person): bool
    {
        return ! Person::query()
            ->where('is_super_admin', true)
            ->whereKeyNot($person->getKey())
            ->exists();
    }
}
