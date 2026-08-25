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
 * A provider call can time out after the provider has accepted the message.
 * `provider_message_id` is written **before** the state moves to `sent`, and
 * the send path refuses an instance that is not `pending`, so a retry after a
 * timeout finds a row it will not send again. The queue's own retry semantics
 * are not enough on their own — Horizon will happily run a job twice.
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
             * Written **before** the state moves to `sent` — see the class
             * docblock. A row carrying one has been accepted by the provider
             * whatever the state says, so it is never sent again.
             */
            $table->string('provider_message_id')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();

            // S47 is "this team's messages needing review, oldest first"; the
            // scheduler asks for pending instances that are due.
            $table->index(['team_id', 'state', 'scheduled_for']);
            $table->index(['deal_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_instances');
    }
};
