<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an automation actually did on 123 Main St (PRD §4.5, §6.2, §8.1 ·
 * S47, S49 · issue #92).
 *
 * The runtime half of #91's definition layer, and the first table in this
 * product that can reach a client. PRD §4.5:
 *
 * > An automation that emails the wrong client the wrong thing damages a real
 * > relationship and cannot be recalled.
 *
 * So this table is **team-scoped through `productDefaults()`**, unlike the
 * definitions it comes from: a row here holds a rendered message with a
 * client's name and address in it, which is customer data in the plainest
 * sense.
 *
 * ## The words are snapshotted, and the pointer is for reporting
 *
 * `payload` holds the rendered subject, bodies and recipients as they stood
 * when the instance was raised — which is what F5.10 describes, *"pre-fills
 * the relevant outbound email with the right recipient and content, ready to
 * review and send"*, and what makes the approval queue's editable preview mean
 * anything. An approver edits `payload`, not the template.
 *
 * That fixes the words at raise time rather than at send time, and the
 * trade-off is deliberate: a template corrected after an instance is queued
 * does not rewrite the queued one. F4.5 already says an in-flight deal is
 * never rewritten by a template edit, and a message somebody has read and
 * approved is the strongest case of that rule there is.
 *
 * ## Idempotency lives in this table, not in the queue
 *
 * A provider call can time out **after** the provider has accepted the
 * message, so an id handed back by the provider is exactly the thing a
 * timed-out send does not have. Two columns rather than one, and the
 * distinction is the whole guarantee:
 *
 *  - `message_key` is **ours**, generated and written before the mailer is
 *    called at all. A row carrying one has been handed to a transport, and is
 *    never handed to one again whatever else the row says.
 *  - `provider_message_id` is **theirs**, written after they answer, and null
 *    for every send that went out and never came back. #95 correlates
 *    delivery notifications on it.
 *
 * The queue's own retry semantics are not enough on their own — Horizon will
 * happily run a job twice, and the second run is exactly the one that must
 * find the door shut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_instances', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * Both, and they answer different questions.
             *
             * `stage_id` is *what raised this*; `deal_id` is *where a person
             * looks for it*. S47 groups a team's pending messages by deal and
             * S49 links back to one, and deriving that through two joins on
             * every row of the approval queue is the shape the budget tests
             * refuse. Same reasoning `activity_events` records for its own
             * pair.
             */
            $table->teamScopedForeign('deal_id', 'deals');
            $table->teamScopedForeign('stage_id', 'stages', nullable: true);

            /*
             * Reporting only, and nullable on purpose: a team may delete the
             * automation that raised this, and that must not delete the record
             * of a message that has already gone to a client. Everything this
             * row needs to execute is snapshotted below.
             */
            $table->foreignUlid('action_definition_id')->nullable();

            // Snapshotted from the definition, so a deleted or edited one
            // cannot change what a queued instance does.
            $table->string('action_type');

            /*
             * What made it fire, and it is load-bearing rather than reporting.
             *
             * `AdvanceWorkflow::reopen()` wrote the contract before this table
             * existed: *"an action that already fired stays fired — a client
             * emailed when the stage first completed must not be emailed again
             * on the second advance"*, and *"the dedupe belongs on the sending
             * side, keyed by the stage and the action rather than by a count
             * of advances."* Reopening and re-advancing a stage raises the
             * same `stage_completion` a second time, and this column plus
             * `stage_id` and `action_definition_id` is the key that catches
             * it. S49 also shows it, which is the smaller of the two reasons.
             */
            $table->string('trigger');
            $table->foreignUlid('message_template_id')->nullable();
            $table->config('config');

            // IA §8: pending (Scheduled) · awaiting_approval (Needs Review) ·
            // sent · failed · cancelled.
            $table->string('state')->default('pending');

            /*
             * Null means *as soon as the queue gets to it*. A date-based
             * trigger sets a future instant and the scheduler picks it up —
             * and a key date that moves reschedules or cancels what is pending
             * (#106), which is the reason this is a column rather than a
             * queue delay nothing can find again.
             */
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('executed_at')->nullable();

            /*
             * The rendered message, and what an approver may edit.
             *
             * Never the template's row: two instances raised from one template
             * are two different messages to two different clients, and an edit
             * to one must not touch the other.
             */
            $table->config('payload');

            // F5.7. Who released it, so the activity entry can name them.
            $table->foreignUlid('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            /*
             * Written **before** the mailer is called — see the class
             * docblock. A row carrying one has been handed to a transport
             * whatever the state says, so it is never handed to one again.
             */
            $table->string('message_key')->nullable();

            /*
             * And what the provider called it, once it answers. Null on every
             * send that timed out, which is the case `message_key` exists for.
             */
            $table->string('provider_message_id')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();

            // S47 is "this team's messages needing review, oldest first"; the
            // scheduler asks for pending instances that are due.
            $table->index(['team_id', 'state', 'scheduled_for']);
            $table->index(['deal_id', 'state']);

            // The "has this already fired" question, asked once per automation
            // per advance. Without it, re-advancing a stage on a busy team is
            // a sequential scan on the widest table in the product.
            $table->index(['stage_id', 'trigger', 'action_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_instances');
    }
};
