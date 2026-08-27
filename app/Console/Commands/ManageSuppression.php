<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SuppressionReason;
use App\Models\SuppressedAddress;
use App\Rules\SendableEmailAddress;
use App\Support\Audit\AuditLogger;
use App\Support\Delivery\Suppression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The door on the suppression list (#95 · PRD §4.5 F5.8).
 *
 * ## Why this exists at all
 *
 * `resources/help/automation.md` tells a team that if a suppressed address
 * comes back to life they should *"ask support"* — and round 1 of review
 * pointed out that support's only route was a `psql` session.
 * `Suppression::lift()` had no caller and `SuppressionReason::Manual` was
 * produced by nothing.
 *
 * That is `CLAUDE.md`'s finding twice over: *"a rail with no UI is a rule
 * nobody can pull"* (F5.9's kill switch needed its own screen) and *"a reader
 * with no writer is as dead as a row nothing can reach"* (`teams.logo_path`
 * shipped a slice early with nothing able to set it). A promise in a help
 * article is a reader; this is the writer.
 *
 * ## A console command rather than a screen, and deliberately
 *
 * The list binds **every** team (see `SuppressedAddress`), so lifting one is a
 * decision about the whole account's standing with Amazon — PRD §12.2's
 * bounce and complaint thresholds — rather than about one team's message. A
 * team that could clear its own suppressions could re-mail the address that
 * caused them, which is precisely the behaviour the thresholds punish.
 *
 * `/admin` would be the other candidate. It is not built for this yet, and a
 * console command with an audit entry satisfies the requirement now; a screen
 * can replace it without changing the semantics.
 */
class ManageSuppression extends Command
{
    protected $signature = 'mail:suppression
        {email : The address to look up, or the row id from an audit entry}
        {--lift : Remove the suppression, so this product will write to it again}
        {--suppress : Add a suppression by hand}
        {--reason= : Why, when suppressing by hand. Free text, stored beside the row}';

    protected $description = 'Inspect, lift, or add an address suppression (audited)';

    public function handle(Suppression $suppression, AuditLogger $audit): int
    {
        $subject = (string) $this->argument('email');

        /*
         * **An id is accepted as well as an address**, which round 3 of review
         * asked for and is a small thing with a specific job.
         *
         * An audit entry records `auditable_id` and deliberately no address —
         * `AuditRedactor` removes one, correctly — so somebody reading
         * `mail.suppression_lifted` had the id of a row and no product path
         * from it to the address. `psql` again, which is the state this
         * command exists to end.
         *
         * Told apart by shape rather than by a flag: an address always has an
         * `@` and a ULID never does.
         */
        if (! str_contains($subject, '@')) {
            $row = SuppressedAddress::withTrashed()->find($subject);

            if (! $row instanceof SuppressedAddress) {
                $this->components->error("No suppression record has the id [{$subject}].");

                return self::FAILURE;
            }

            /*
             * **Resolved, then carried on through the flags** — round 4 of
             * review. The first version returned straight into the report, so
             * `mail:suppression <id> --lift` printed a page, exited **0**, and
             * lifted nothing. That is the command an operator holding an audit
             * entry actually types (the entry records an id and, correctly, no
             * address), and a runbook step or a `&&` chain reads the exit code
             * as success.
             *
             * A door that leads somewhere read-only while silently swallowing
             * the verb is worse than not accepting an id at all — which is the
             * same class of finding the id path was added to close.
             */
            $subject = $row->email;
        }

        $email = $subject;

        /*
         * Validated before anything is written. An address that cannot be
         * parsed cannot be one this product ever sent to, so a typo would
         * otherwise leave a row nothing can ever match — permanent, invisible,
         * and account-wide.
         */
        /*
         * `SendableEmailAddress`, not Laravel's `email` rule — round 2 of
         * review, and `CLAUDE.md`'s own finding: *"validate with the parser
         * that will consume it."* `emily(work)@bosart.test` is a legal RFC
         * 5322 address with a comment in it, passes `email` and `email:rfc`,
         * and throws inside `Symfony\Component\Mime\Address` — so it would
         * have stored a permanent, account-wide row matching an address this
         * product can never send to. Which is exactly what the comment below
         * says this check exists to prevent.
         */
        $rules = ['email' => ['required', 'email', new SendableEmailAddress]];

        if (Validator::make(['email' => $email], $rules)->fails()) {
            $this->components->error("[{$email}] is not an email address.");

            return self::FAILURE;
        }

        $email = SuppressedAddress::normalise($email);

        if ($this->option('lift') && $this->option('suppress')) {
            $this->components->error('Choose one of --lift and --suppress.');

            return self::FAILURE;
        }

        if ($this->option('lift')) {
            return $this->lift($suppression, $audit, $email);
        }

        if ($this->option('suppress')) {
            return $this->suppress($suppression, $audit, $email);
        }

        return $this->show($email);
    }

    private function show(string $email): int
    {
        /*
         * `withTrashed()`, because a lift is a soft delete and the history is
         * the point: *"it is not suppressed"* with no trace that it ever was
         * is the answer that sends somebody back to the database.
         */
        $row = SuppressedAddress::withTrashed()->where('email', $email)->first();

        if (! $row instanceof SuppressedAddress) {
            $this->components->info("[{$email}] is not suppressed, and never has been.");

            return self::SUCCESS;
        }

        if ($row->trashed()) {
            $this->components->info("[{$email}] is not suppressed.");
            $this->components->twoColumnDetail('Was suppressed for', $row->reason->label());
            $this->components->twoColumnDetail('Lifted', $row->deleted_at?->toDayDateTimeString() ?? '—');
            $this->newLine();
            $this->comment('  Mail to it goes out normally. Suppress it again with --suppress.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Address', $email);
        $this->components->twoColumnDetail('Reason', $row->reason->label());
        $this->components->twoColumnDetail('Since', $row->suppressed_at->toDayDateTimeString());
        $this->components->twoColumnDetail('Provider said', $row->detail ?? '—');
        /*
         * Shown here and nowhere a team can reach, which is the line
         * `SuppressedAddress` draws: an operator running a console command is
         * already above the tenancy, and a team learning which *other* team's
         * message produced a suppression is the disclosure the table is
         * careful about.
         */
        $this->components->twoColumnDetail('Discovered by team', $row->discovered_by_team_id ?? '— (team since removed)');

        $this->newLine();
        $this->line('  '.$row->reason->explanation());
        $this->newLine();
        $this->comment('  Lift it with --lift. This affects every team on the platform.');

        return self::SUCCESS;
    }

    private function lift(Suppression $suppression, AuditLogger $audit, string $email): int
    {
        $existing = SuppressedAddress::query()->where('email', $email)->first();

        if (! $existing instanceof SuppressedAddress) {
            $this->components->warn("[{$email}] was not suppressed. Nothing to do.");

            return self::SUCCESS;
        }

        $suppression->lift($email);

        /*
         * Audited because it is a decision somebody will want to find later:
         * a lifted suppression that starts bouncing again is exactly the
         * sequence that damages the account's sending reputation, and *"who
         * decided this address was fine"* is the first question asked.
         *
         * No team and no actor, for the reasons `PromotePlatformAdministrator`
         * gives: the record spans every tenant, and an operator with a shell
         * is not a person the application knows.
         */
        $audit->record(
            action: 'mail.suppression_lifted',
            auditable: $existing,
            teamId: null,
            actorPersonId: null,
            reason: 'Lifted from the console by a server operator.',
            before: ['reason_code' => $existing->reason->value],
        );

        $this->components->info("[{$email}] is no longer suppressed. This product will write to it again.");

        return self::SUCCESS;
    }

    private function suppress(Suppression $suppression, AuditLogger $audit, string $email): int
    {
        $detail = $this->option('reason');

        $added = $suppression->record(
            email: $email,
            reason: SuppressionReason::Manual,
            detail: is_string($detail) && $detail !== '' ? $detail : 'Added from the console.',
        );

        if (! $added) {
            /*
             * Two ways to be refused, and they are opposite facts. Round 4 of
             * review: this said *"was already suppressed"* for both, including
             * the case where the row is **lifted** and the refusal left the
             * address perfectly writable — the message asserting the reverse
             * of what had happened.
             */
            $current = SuppressedAddress::withTrashed()->where('email', $email)->first();

            $this->components->warn($current?->trashed() === false
                ? "[{$email}] was already suppressed. Left as it was."
                : "[{$email}] was not suppressed, and this did not suppress it.");

            return self::SUCCESS;
        }

        $row = SuppressedAddress::query()->where('email', $email)->sole();

        $audit->record(
            action: 'mail.suppression_added',
            auditable: $row,
            teamId: null,
            actorPersonId: null,
            reason: 'Added from the console by a server operator.',
            after: ['reason_code' => SuppressionReason::Manual->value],
        );

        $this->components->info("[{$email}] is suppressed. Nothing will be sent to it by any team.");

        return self::SUCCESS;
    }
}
