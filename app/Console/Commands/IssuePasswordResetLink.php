<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

/**
 * Print a password reset link (ADR 0003).
 *
 * ## The gap this closes
 *
 * Forgotten-password recovery had exactly one channel. A team owner locked
 * out of a staging environment, or out of a fresh local one where mail goes
 * nowhere, had no way back in that did not involve editing a password hash by
 * hand — which is worse than this in every respect, including the one that
 * matters: nothing records that it happened.
 *
 * ADR 0003 says every flow needs a second door. In production that door is
 * usually another *person* — a team owner issuing an invite link, an operator
 * running this. It is deliberately not a *screen*: a page that mints reset
 * links for other accounts is an account-takeover button, however carefully
 * it is gated.
 *
 * ## What it does not do
 *
 * It does not change or clear a password, and it does not sign anybody in. It
 * mints the same single-use, expiring token the emailed link carries, through
 * the same broker — so the same expiry and the same one-shot consumption
 * apply. An operator running this can start a reset; only the account holder
 * can finish one.
 *
 * Two things it does **not** inherit from `sendResetLink`, both worth knowing
 * before running it against a customer's account:
 *
 *  - **It rotates.** `DatabaseTokenRepository::create()` deletes any existing
 *    token for the address first, so this kills a link the account holder
 *    requested by email a minute ago, and a later emailed request kills this
 *    one. Same trade as the invitation link, for the same reason: nothing
 *    recoverable is stored.
 *  - **It skips the resend throttle.** `tokenRecentlyCreated` gates the
 *    *screen*, not the broker's `createToken`, so this is not rate-limited by
 *    anything except shell access.
 */
class IssuePasswordResetLink extends Command
{
    use ConfirmableTrait;

    protected $signature = 'auth:reset-link
                            {email : The sign-in address of an existing account}
                            {--force : Skip the confirmation prompt in production}';

    protected $description = 'Print a single-use password reset link for an existing account.';

    public function handle(AuditLogger $audit): int
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->components->error('Password resets are disabled, so there is no link to issue.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        $person = Person::query()->whereRaw('lower(email) = ?', [$email])->first();

        /*
         * The forgot-password *screen* answers identically whether or not the
         * address exists, and must: it is unauthenticated, and the answer is
         * an account-enumeration oracle. This is a shell on the server, where
         * the enumeration question is already lost and a vague answer only
         * costs the operator a debugging session.
         */
        if (! $person instanceof Person || ! $person->hasCredentials()) {
            $this->components->error("No account signs in as [{$email}].");

            $this->components->info(
                'Only an account with a password can reset one. A contact in a team directory '
                .'is not an account — invite them instead, with `invitation:link` if mail is not '
                .'configured here.',
            );

            return self::FAILURE;
        }

        /*
         * The default broker's own `createToken`, reached through the facade
         * rather than through `broker()`: the contract `broker()` returns does
         * not declare it, and narrowing to the concrete class with a docblock
         * would be asserting an implementation nobody promised. Same token,
         * same repository, same expiry and same one-shot consumption as the
         * emailed link — which is the whole point.
         */
        $token = Password::createToken($person);

        /*
         * PRD §9 audits authentication events, and starting a reset for
         * somebody else's account from a shell is the most sensitive one this
         * product has. No team — an account spans them — and no actor, because
         * an operator with a shell is not a person the application knows.
         */
        $audit->record(
            action: 'auth.password_reset_link_issued',
            auditable: $person,
            teamId: null,
            actorPersonId: null,
            reason: 'Issued from the console by a server operator.',
        );

        $this->newLine();
        $this->line(route('password.reset', ['token' => $token, 'email' => $person->email]));
        $this->newLine();

        $this->components->warn(
            'Single use, and it expires like any other reset link. Anyone holding it can set '
            .'this account’s password, so hand it over the way you would a password.',
        );

        // The surprising half, said where somebody will actually read it.
        $this->components->warn(
            'This replaces any reset link already outstanding for this address, including one '
            .'the account holder requested themselves.',
        );

        return self::SUCCESS;
    }
}
