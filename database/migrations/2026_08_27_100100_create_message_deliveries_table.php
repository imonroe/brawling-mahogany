<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What became of each copy of each message (PRD §4.5 F5.8 · issue #95).
 *
 * PRD §1.1 says the product answers two questions, and the second is *"has the
 * client been told?"*. Until now the strongest answer available was
 * `action_instances.state = sent`, which means *this product handed the
 * message to a transport and the transport did not object* — a different and
 * much weaker claim. This table is where the answer actually lives, and PRD
 * §12.2's *"messages delivered successfully above 98%"* is measured off it.
 *
 * ## One row per recipient, not per message
 *
 * A stage-completion email addressed to *the Seller* goes to two people on a
 * deal with two sellers, and one of those addresses can be dead while the
 * other is fine. `action_instances` cannot express that — it has one `state`
 * column — so a row here is (instance × recipient), and the send path writes
 * as many as it addressed.
 *
 * ## Correlated on the provider's id, which is why it is nullable
 *
 * SNS notifications name a message by the id **SES** assigned, so that is the
 * join. It is nullable because the send that timed out has no provider id at
 * all — `action_instances` already argues that at length, and this table
 * inherits the consequence: a row with a null `provider_message_id` is one
 * nothing will ever come back about, and it stays `sent` forever. The screen
 * says *sent*, which is exactly what is known.
 *
 * ## No `suppression_reason` column
 *
 * Issue #95's sketch has one. It is on `suppressed_addresses` instead, because
 * suppression is a fact about an address rather than about a delivery, and
 * copying it here would let the two disagree — a delivery row saying an
 * address is suppressed while the suppression has been lifted is worse than no
 * column. What a delivery does record is `detail`: what the provider said
 * about *this* attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_deliveries', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('action_instance_id', 'action_instances');

            /*
             * Who it was addressed to, where that is still a row somebody can
             * open. Nullable for two ordinary reasons: F5.9's sandbox
             * redirects every message to the team owner regardless of who it
             * was for, and a participant can be removed from a deal after the
             * message went out. The address below is the fact; this is the
             * convenience.
             *
             * A `team_memberships` row, never a `people` one — IA §11's
             * "Person, not User" split. A client has a membership with an
             * email and usually no `Person` at all, so keying this on a person
             * would record nothing for exactly the recipients this table is
             * about. (Issue #95's sketch says `recipient_person_id`; it
             * predates #140 moving contact details onto the membership.)
             */
            $table->teamScopedForeign('team_membership_id', 'team_memberships', nullable: true);

            /*
             * The address actually written to, as it was written. This is what
             * a bounce notification names and what suppression keys on, and it
             * has to survive the membership being edited afterwards — a
             * corrected address must not rewrite the history of the message
             * that bounced off the wrong one.
             */
            $table->string('recipient_email');

            $table->string('channel');

            /*
             * Indexed on its own, and not composited with `team_id`, because
             * the webhook arrives from outside the tenancy and has only this
             * to go on. That is the one lookup in the product that starts
             * without a team, which is why `RecordDeliveryEvent` runs
             * unscoped and says so.
             */
            $table->string('provider_message_id')->nullable()->index();

            $table->string('status');

            /*
             * Four moments rather than one, because they are not exclusive: a
             * message delivered at 09:00 and opened at 11:00 has both, and a
             * message that bounced after being delivered to the server has
             * `delivered_at` and `bounced_at` both set. `status` carries the
             * furthest one (see `DeliveryStatus::rank()`); these carry when.
             */
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();

            /*
             * When **this product learned** the message had failed, which is a
             * different fact from when it failed and the only one the alert
             * sweep can key on.
             *
             * The four columns above carry Amazon's clock. A bounce
             * notification routinely arrives minutes after the event and can
             * arrive hours later, so a sweep windowing on `bounced_at` would
             * find a genuine bounce already **behind** its high-water mark and
             * never mention it — the failure `AlertOnFailures` documents about
             * `executed_at`, made worse by the timestamp belonging to somebody
             * else's system entirely.
             *
             * Written once, on the transition into a failure status, so a
             * replayed notification cannot drag a row back in front of the
             * mark. That is the `COALESCE(executed_at, updated_at)` finding
             * one table over: a column the alert windows on must not be
             * movable by an ordinary save.
             */
            $table->timestamp('noticed_at')->nullable();

            /*
             * Why this copy was never sent, when it was not (#95, round 1 of
             * review). Null for every row that actually reached a transport.
             *
             * This is the `suppression_reason` issue #95's sketch asks for,
             * and an earlier draft of this migration argued it out on the
             * grounds that a stored reason could disagree with a suppression
             * later lifted. That argument was right about the wrong column: a
             * row here is not a claim about the address **now**, it is a
             * record of why *this send* was withheld at the moment it was
             * withheld — a historical fact, which is exactly what a delivery
             * row is for.
             */
            $table->string('withheld_reason')->nullable();

            /*
             * Whether F5.9's sandbox sent this to the team owner instead of
             * the person it was addressed to.
             *
             * On the row rather than inferred, because S49 renders the
             * recipient and a redirected row otherwise shows the owner's name
             * beside a message addressed to a client with nothing explaining
             * the difference — which is the same *"'Emailed Ian Monroe' about
             * a message meant for the seller"* sentence `ExecuteAction` already
             * carries a warning about.
             */
            $table->boolean('redirected')->default(false);

            /*
             * The provider's own words about this attempt. Never the
             * explanation a person reads — #95 asks for plain language, and
             * `smtp; 550 5.1.1` is not it.
             */
            $table->text('detail')->nullable();

            // S49 lists a message's deliveries; the alert sweep asks a team
            // for its recent failures.
            $table->index(['team_id', 'status', 'created_at']);
            // The alert sweep's own window: this team's failures, by when we
            // learned of them.
            $table->index(['team_id', 'noticed_at']);
            $table->index(['action_instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
    }
};
