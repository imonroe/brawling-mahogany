<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Message templates (PRD §4.5 F5.5, F5.6, §7.12 · S45, S46 · issue #90).
 *
 * PRD §7.12 is the correction this table implements:
 *
 * > `Email Template` points the wrong way, and should generalise. Templates
 * > should be **independent and referenced *by* actions**, and recipients
 * > should be **a rule rather than an address.**
 *
 * So the table is `message_templates` rather than `email_templates`, it
 * carries a `channel`, it holds a `recipient_rule` and no address column at
 * all, and nothing in it points at an action definition — the pointer goes the
 * other way (#91).
 *
 * ## Team-scoped, and not the nullable-team shape the definition layer uses
 *
 * `workflow_templates`, `stage_templates` and their children take a nullable
 * `team_id` so a pack can ship one shared row to every team. This table
 * deliberately does not, and the difference is what the rows hold.
 *
 * A stage template says *"Listing Preparation, 5 days, owned by the
 * transaction coordinator"* — it names no client and belongs to nobody. A
 * message template is **the team's own words to their own clients**, with
 * their signature under it and their sending identity on it. It is the closest
 * thing in the definition layer to customer data, so it gets all five
 * enforcement layers (ADR 0002) rather than a named scope.
 *
 * That decides how a pack will eventually ship one, and the answer is already
 * the answer for workflow templates: **copy it in**, the way
 * `CopyTemplate` does. A shared row would put one team's words in another
 * team's outbox — and `WorkflowTemplate::inUseCount()` records what a count
 * taken off a shared row costs.
 *
 * ## `body_text` is NOT NULL
 *
 * Design System §12: *"A real plain-text alternative for every message, not a
 * stripped-tag afterthought."* A nullable column is one that is null on most
 * rows, and the plain-text part is the half that reaches a watch, a screen
 * reader and a client whose mail client blocks HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->productDefaults();

            $table->string('name');

            // email · push · sms. PRD §7.12 v0.2; `sms` is a value nothing
            // sends yet and `MessageChannel::selectableOptions()` withholds it.
            $table->string('channel')->default('email');

            // Null on a channel with no subject line — push has a title and a
            // body and nothing else.
            $table->string('subject')->nullable();

            $table->text('body_html')->nullable();
            $table->text('body_text');

            /*
             * *Who*, as a rule (PRD §7.12).
             *
             * `{"type": "participant_role", "participantRole": "seller"}`, and
             * resolved against `deal_participants` at send time by
             * `App\Support\Messages\ResolveRecipients`. NOT NULL because a
             * template with no recipient rule is a template that cannot be
             * sent, and a nullable column would let one be saved.
             */
            $table->config('recipient_rule', nullable: false);

            /*
             * Which sending identity this template goes out from (#94).
             *
             * Null means the team's own, which is what almost every template
             * wants. It is here now because the column belongs beside the
             * template rather than because Slice 3's SES work has landed —
             * PRD §5.1 step 3 lets a team accept a shared default identity, so
             * an override has to be expressible per template.
             */
            $table->string('from_identity')->nullable();

            /*
             * Archived, never deleted — the rule S76 set and every lookup
             * screen since has followed (Frontend conventions §4).
             *
             * A template is a value other rows point at: `action_definitions`
             * name one, and a team with three automations standing on
             * "Inspection scheduled" who deletes it has broken three
             * automations to solve a tidiness problem. Archiving takes it out
             * of S44's picker, leaves the automations already on it alone, and
             * is reversible — which is what somebody actually means when they
             * try to delete one.
             *
             * `workflow_templates` has a destroy and this does not, and the
             * difference is real rather than an inconsistency: instantiation
             * *snapshots* a workflow template, so a running deal holds no
             * pointer back to it. An automation holds a live pointer here.
             */
            $table->timestamp('archived_at')->nullable();

            $table->index(['team_id', 'channel', 'archived_at']);
        });

        /*
         * One live name per team per channel.
         *
         * Per channel rather than per team: "Inspection scheduled" is a
         * reasonable name for both the email to the seller and the push to the
         * agent, and they are different rows. Partial on `deleted_at` **and**
         * `archived_at`, because archiving frees the name — that is the
         * documented way out of *"I archived the wrong one, let me start
         * clean"*, and a rule that filtered only one of the two showed
         * somebody an "Archived" badge with no explanation of why the name was
         * taken. `lower(name)` so the index folds case the way Postgres does;
         * see `DealTypeRules`, where a rule that folded in PHP instead
         * disagreed with the index on non-ASCII names.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX message_templates_team_name_unique
                ON message_templates (team_id, channel, lower(name))
                WHERE deleted_at IS NULL AND archived_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
