<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5.9's three rails, as columns (PRD §4.5 · issue #96).
 *
 * PRD §4.5 calls F5.7 and F5.9 **launch blockers, not enhancements**, and
 * these land in the same change as the first thing able to send. A rail that
 * arrives after the send path is a rail that was not there for the sends in
 * between.
 *
 * ## Columns rather than the `settings` blob
 *
 * `Team::sendsAreDisabled()` has read `settings['no_sends']` since Slice 1,
 * with a docblock saying the accessor exists *"so nothing has to guess at the
 * key later"* — this is that later. A safety control in a JSON blob is a
 * control nothing can query: *"which teams are currently halted"* is a
 * question an operator asks during an incident, and `settings->>'no_sends'`
 * across every row is not an answer. The migration carries any existing value
 * across so an install that has set one keeps it.
 *
 * ## Why the kill switch is a timestamp and the sandbox is a boolean
 *
 * Halting sending is an incident, and *when* somebody pulled the cord is part
 * of what happened. Sandbox mode is a configuration — a team tuning their
 * templates, or staging, where PRD §8.6 requires that *"no test ever reaches a
 * real client"* permanently.
 *
 * ## The limits have defaults, and the defaults are the point
 *
 * F5.9: *"A bug that loops an automation should hit a wall after ten messages,
 * not after four hundred."* Sixty an hour and two hundred a day is generous
 * for a team running twenty-five deals and nowhere near enough to matter if
 * something loops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            // Rail 2 — the hard "no sends" switch. Honoured at the last
            // possible moment before the provider call, not at dispatch, so it
            // catches everything already queued.
            $table->timestamp('sends_disabled_at')->nullable();
            $table->string('sends_disabled_reason')->nullable();

            /*
             * Rail 3 — sandbox mode. Every message goes to the team owner
             * instead of its real recipients.
             *
             * **On by default for a new team**, which is the same argument
             * F5.7 makes for defaulting to approval: the period when a team's
             * templates are least tested is the period their clients are most
             * at risk from them. A team turns it off when they have watched a
             * few messages arrive.
             */
            $table->boolean('sandbox_mode')->default(true);

            // Rail 1 — the ceiling, per team.
            $table->unsignedSmallInteger('hourly_send_limit')->default(60);
            $table->unsignedSmallInteger('daily_send_limit')->default(200);

            /*
             * F5.7's *"default to approval for a team's first 30 days"*, as a
             * date rather than as advice in the documentation.
             *
             * Nullable because it expires: past this instant an automation's
             * own `requires_approval` decides, and before it every message
             * waits whatever the automation says. Set on team creation.
             */
            $table->timestamp('approval_required_until')->nullable();
        });

        /*
         * Carry the old flag across. `sendsAreDisabled()` read this key from
         * Slice 1 and something may have set it; silently dropping a halt is
         * the one migration failure this table cannot afford.
         */
        DB::statement(<<<'SQL'
            UPDATE teams
               SET sends_disabled_at = COALESCE(sends_disabled_at, now()),
                   sends_disabled_reason = COALESCE(sends_disabled_reason, 'Carried over from settings.no_sends')
             WHERE (settings ->> 'no_sends')::boolean IS TRUE
        SQL);

        /*
         * Every team that already exists gets the 30-day window from **now**
         * rather than from when they signed up. The rail exists to protect a
         * team whose templates are untested, and no team has ever sent a
         * message from this product.
         */
        DB::statement("UPDATE teams SET approval_required_until = now() + interval '30 days'");
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn([
                'sends_disabled_at',
                'sends_disabled_reason',
                'sandbox_mode',
                'hourly_send_limit',
                'daily_send_limit',
                'approval_required_until',
            ]);
        });
    }
};
