<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses this product will not write to again (PRD §4.5 F5.8 · issue #95).
 *
 * ## The one table with no `team_id`, on purpose
 *
 * Every other business table in this product carries one, and ADR 0002 calls a
 * gap there a release blocker. This is the documented exception, and issue #95
 * asks for it in as many words: *"A suppressed address must not be re-mailed
 * by **any** team. This is the one place where a deliberately cross-tenant
 * record is correct — and it needs to be built explicitly rather than falling
 * out of a scope gap."*
 *
 * The reason is that the fact recorded here is a fact about the **address**,
 * not about the team that happened to send to it. A mailbox that does not
 * exist does not exist for anybody. And the cost of getting it wrong is
 * asymmetric in a way that is worth stating plainly: SES measures bounce and
 * complaint rates **per account** (PRD §12.2 — bounce under 2%, complaint
 * under 0.1%), so one team writing repeatedly to a dead address is spending
 * every other team's deliverability. Scoping this per team would mean each new
 * team gets to rediscover the same bad address, at the account's expense.
 *
 * ## Which makes it a disclosure question, so it is not readable by a team
 *
 * The row says an address exists, is dead, or reported somebody as a spammer.
 * Two teams sharing a client would otherwise learn something about each
 * other's correspondence — so nothing team-facing reads this table. A send is
 * refused with *"we are not writing to this address"*, and **which** team's
 * message caused the suppression is only ever visible in the platform admin
 * console. `SuppressedAddress` holds that argument beside the code.
 *
 * ## It outlives the team, deliberately
 *
 * Issue #57 asks whether suppression entries survive a team purge. They must:
 * the address is still dead after the team that discovered it has gone, and a
 * purge that resurrects a suppressed address hands the account's reputation
 * back to the same bounce. There is no `team_id` for the purge to key on, so
 * this happens by construction rather than by an exception in the sweep —
 * which is the shape to prefer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressed_addresses', function (Blueprint $table): void {
            /*
             * Not `productDefaults()`, which would add `team_id`. Everything
             * else it gives is wanted, so those are spelled out — a table
             * skipping the macro is a table the next reader should be able to
             * see the whole shape of.
             */
            $table->ulid('id')->primary();

            /*
             * Lower-cased on the way in and unique, which is what makes replay
             * harmless: SNS delivers a bounce at least once and sometimes
             * three times, and *"already suppressed"* is the correct outcome
             * of the second one. No dedupe table, no notification-id ledger —
             * the constraint is the idempotency.
             *
             * Not the local part's case-sensitivity argument: RFC 5321 permits
             * a case-sensitive local part and no mail provider in this
             * century honours it, and a suppression that misses because
             * somebody typed a capital is a bounce this product chose to
             * repeat.
             */
            $table->string('email')->unique();

            $table->string('reason');

            /*
             * The provider's own words — `smtp; 550 5.1.1 user unknown`, or
             * the SES bounce subtype. Kept because somebody debugging
             * deliverability wants it, and never shown as the explanation:
             * #95 asks for *"plain language — not SMTP 550"*, and
             * `SuppressionReason::explanation()` is what the screen leads with.
             */
            $table->text('detail')->nullable();

            /*
             * Which team's message produced this, for the platform console
             * only. Nullable and `ON DELETE SET NULL` rather than cascade:
             * the suppression outlives the team, so a purge must clear the
             * pointer and leave the fact.
             */
            $table->foreignUlid('discovered_by_team_id')->nullable()
                ->constrained('teams')->nullOnDelete();

            $table->timestamp('suppressed_at');

            $table->timestamps();

            // "Is this address suppressed" is asked once per recipient per
            // send; the unique index above already answers it. This one is for
            // the console's "what happened this week".
            $table->index(['reason', 'suppressed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_addresses');
    }
};
