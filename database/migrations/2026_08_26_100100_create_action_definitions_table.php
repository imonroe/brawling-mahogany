<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automations, as a team defines them (PRD §4.5 F5.1–F5.4, F5.10 · S44 ·
 * issue #91).
 *
 * The **definition** half. This hangs off `stage_templates` and says *when*
 * something should happen and *what*; `action_instances` (#92) is what
 * actually happens on 123 Main St, and `InstantiateWorkflow` is the one-way
 * door between them exactly as it is for stages, gates and tasks.
 *
 * ## `team_id` mirrors the stage template's, and it is load-bearing
 *
 * `stage_templates` takes a nullable team so a pack can ship one shared row to
 * every team. `message_templates` deliberately does not (see that migration:
 * they hold a team's own words to their own clients). An automation joins the
 * two, so it is the row where those two shapes meet — and the meeting is a
 * cross-tenant hazard rather than a curiosity:
 *
 *   a **system** automation (`team_id` null, shared by every team) pointing at
 *   **one team's** message template would send that team's words, with their
 *   signature, from every other team on the platform.
 *
 * Two constraints close it, and both are needed. The composite foreign key
 * refuses a template belonging to a different team — but Postgres foreign keys
 * are MATCH SIMPLE, so a NULL `team_id` satisfies the constraint without
 * checking anything, which is exactly the system row. The CHECK is what
 * catches that half: **a shared automation may not name a template at all.**
 *
 * A pack that eventually wants to ship an emailing automation therefore copies
 * its message template into the team at install time, the way `CopyTemplate`
 * already copies a workflow template. That is a decision this migration makes
 * rather than defers.
 *
 * ## `is_manual` and `requires_approval` are mutually exclusive
 *
 * Both put a human in the loop and F5.4 and F5.7 describe the same moment from
 * two ends: *presented to a human as a prompt*, and *queued for approval with
 * an editable preview*. An automation that did both would ask two people to
 * agree to one email — so S44 offers **one** three-way choice ("fires on its
 * own" · "needs approving first" · "prompts somebody to do it") rather than
 * two checkboxes with four states of which two are nonsense. The Screen
 * Inventory asks for exactly that: *"a progressive form that narrows, not four
 * independent dropdowns that can be combined into nonsense."*
 *
 * The columns stay as PRD §6.2 names them; the invariant lives in the
 * database, so a route added in a later slice inherits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_definitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * Nullable, and always equal to the parent stage template's — see
             * the class docblock. Not `productDefaults()`, for the reason
             * `deal_types` is not: that macro's `team_id` is deliberately NOT
             * NULL because on a table holding customer data a null tenant is a
             * leak, and this table holds a team's *process*.
             */
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();

            $table->foreignUlid('stage_template_id')->constrained('stage_templates')->cascadeOnDelete();

            // F5.2. Resolved to a value of App\Enums\AutomationTrigger.
            $table->string('trigger');

            // F5.3. Resolved to a value of App\Enums\AutomationActionType.
            $table->string('action_type');

            /*
             * The words this automation sends, when it sends any (#90).
             *
             * `nullOnDelete` rather than a cascade: deleting a template must
             * not silently delete the automations that used it. The automation
             * survives, pointing at nothing, and S44 shows it as needing a
             * template — which is a problem somebody can see and fix. A
             * cascade would remove the reminder along with the words.
             */
            $table->foreignUlid('message_template_id')->nullable();

            /*
             * Everything the trigger and the action type need beyond their own
             * names, keyed by who reads it:
             *
             *   gateTemplateId   — which requirement clearing fires this
             *   offsetDays       — signed, for the date-based triggers
             *   taskTitle        — what a `create_task` automation creates
             *   taskOwnerRole    — and who owns it, as a role (never a person)
             *   taskDueOffsetDays
             *   instruction      — what a `manual_prompt` asks somebody to do
             */
            $table->config('config');

            // The in-use count S45 shows before an edit is a query on this
            // column, and the composite foreign key below creates no index of
            // its own.
            $table->index(['message_template_id']);

            // F5.7. Only meaningful on an action that sends something; the
            // check below refuses it elsewhere.
            $table->boolean('requires_approval')->default(false);

            // F5.4.
            $table->boolean('is_manual')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage_template_id', 'sort_order']);
            $table->index(['team_id', 'is_active']);

            /*
             * The composite target `action_instances` (#92) will point back
             * at, matching every other table in this schema.
             */
            $table->unique(['team_id', 'id']);
        });

        /*
         * A team's automation may only name that team's template.
         *
         * MATCH SIMPLE, so this is silent when `team_id` is null — which is
         * precisely the system row the CHECK below refuses to let carry a
         * template at all. The two together are the guarantee; either one
         * alone has a door beside it.
         *
         * `SET NULL (message_template_id)` names the column, and the
         * parenthesised form is the whole point: a bare `SET NULL` on a
         * composite key nulls **every** referencing column, so hard-deleting a
         * template would have cleared `team_id` too and quietly promoted one
         * team's automation into a shared one. Postgres 15 added the column
         * list; this schema is pinned to 16.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE action_definitions
                ADD CONSTRAINT action_definitions_message_template_foreign
                FOREIGN KEY (team_id, message_template_id)
                REFERENCES message_templates (team_id, id)
                ON DELETE SET NULL (message_template_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE action_definitions
                ADD CONSTRAINT action_definitions_shared_rows_carry_no_template
                CHECK (team_id IS NOT NULL OR message_template_id IS NULL)
        SQL);

        /*
         * F5.4 and F5.7 are one choice, not two. See the class docblock.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE action_definitions
                ADD CONSTRAINT action_definitions_one_human_in_the_loop
                CHECK (NOT (is_manual AND requires_approval))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('action_definitions');
    }
};
