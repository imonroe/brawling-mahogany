<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow **runtime** layer (PRD §4.4 F4.6, F4.7, §6.2 · IA §3, §8 ·
 * issue #65).
 *
 * What is actually happening on 123 Main St, as opposed to what the team
 * intends to happen in general. Every table here is team-scoped through
 * `productDefaults()` and reached by composite keys, because these hold
 * customer data and the definition tables do not.
 *
 * ## One deal, many workflows (F4.7)
 *
 * PRD §7.5: the rough data model gave a deal one workflow and then
 * contradicted itself about it. Pre-listing improvements and the sale itself
 * run concurrently, and Emily and Heather's phases — pre-listing, signed
 * listing, under contract, inspection — are exactly that shape. So `workflows`
 * hangs off `deals` with no uniqueness on `deal_id`, and instantiating the same
 * template twice produces two independent runs.
 *
 * ## Nothing here points at a template for behaviour
 *
 * `workflow_template_id` is retained for reporting and for S41's in-use
 * warnings. `template_snapshot` is what the workflow actually *is*. F4.5:
 * later template edits must never rewrite an in-flight deal, and the only way
 * to guarantee that is for the running thing to hold its own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->productDefaults();
            $table->teamScopedForeign('deal_id', 'deals');

            /*
             * Nullable and null-on-delete on purpose. A team may delete the
             * template a running workflow came from, and that must not delete
             * the workflow or break it — the snapshot is what it runs on.
             * Losing the pointer costs a reporting join and nothing else.
             */
            $table->foreignUlid('workflow_template_id')->nullable()
                ->constrained('workflow_templates')->nullOnDelete();

            /*
             * The whole definition, as it stood at instantiation.
             *
             * This is the snapshot F4.5 requires. It exists so the original is
             * recoverable even if every template row is later deleted, and so
             * "what did this deal's process actually say in August" has an
             * answer in November.
             */
            $table->config('template_snapshot', nullable: false);

            $table->string('name');
            $table->string('state')->default('not_started');

            /*
             * Deliberately *not* a composite key, and this is the one place
             * that convention is broken on purpose.
             *
             * `stages.workflow_id` already carries a composite key back to
             * here, so pointing forward with another one makes a circular
             * dependency Postgres will not let either table be created with.
             * The pair is kept honest by `Workflow::activeStage()` reading
             * through the `stages` relation instead, which is team-scoped
             * anyway.
             */
            $table->foreignUlid('current_stage_id')->nullable();

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();

            $table->index(['team_id', 'state']);
        });

        Schema::create('stages', function (Blueprint $table): void {
            $table->productDefaults();
            $table->teamScopedForeign('workflow_id', 'workflows');

            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order');

            $table->string('state')->default('pending');

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();

            $table->foreignUlid('completed_by')->nullable()->constrained('people')->nullOnDelete();

            /*
             * Skip and Override are different actions with different audit
             * consequences and IA §7 forbids conflating them. A skipped stage
             * never happened; an overridden *gate* should have been met and
             * was not. The reason is stored because a skip with no reason is
             * indistinguishable from a mistake six weeks later.
             */
            $table->text('skipped_reason')->nullable();

            // IA §3: a stage is a period, a milestone is a moment. One boolean
            // and one string, not a table. `milestone_label` is what the
            // client reads; the internal name never reaches them (IA §9).
            $table->boolean('is_milestone')->default(false);
            $table->string('milestone_label')->nullable();

            $table->index(['team_id', 'state']);
            $table->index(['workflow_id', 'sort_order']);
        });

        Schema::create('gates', function (Blueprint $table): void {
            $table->productDefaults();
            $table->teamScopedForeign('stage_id', 'stages');

            $table->string('gate_type');
            $table->string('label');
            $table->config('config');
            $table->boolean('is_blocking')->default(true);

            $table->boolean('is_met')->default(false);
            $table->timestamp('met_at')->nullable();
            $table->foreignUlid('met_by')->nullable()->constrained('people')->nullOnDelete();

            /*
             * Overridden is not a kind of met (IA §8, and `GateState`'s own
             * docblock). It means the gate should have been met, was not, and
             * somebody went ahead with a reason — which is why all three
             * columns exist and why S24 writes an audit entry and a follow-up
             * task rather than just flipping a boolean.
             */
            $table->boolean('overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignUlid('overridden_by')->nullable()->constrained('people')->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['stage_id', 'sort_order']);
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * A task lives under a stage but belongs to a deal.
             *
             * `stage_id` is nullable — an ad-hoc job, or one extraction
             * creates in Slice 5, exists on the deal outside any stage.
             * `deal_id` is not, because My Work (S11) groups by deal and a
             * task belonging to nothing has nowhere to appear.
             */
            $table->teamScopedForeign('deal_id', 'deals');
            $table->teamScopedForeign('stage_id', 'stages', nullable: true);

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignUlid('assignee_id')->nullable()->constrained('people')->nullOnDelete();

            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('completed_by')->nullable()->constrained('people')->nullOnDelete();

            // Feeds the `required_tasks_complete` gate (#67).
            $table->boolean('is_required')->default(false);

            // manual · template · extracted. Slice 5 needs to be able to say
            // "the machine put this here" on a screen (PRD §4.10).
            $table->string('source')->default('manual');

            $table->unsignedSmallInteger('sort_order')->default(0);

            // My Work (S11) is "my open tasks, soonest first" across deals.
            $table->index(['team_id', 'assignee_id', 'completed_at', 'due_date']);
            $table->index(['stage_id', 'is_required', 'completed_at']);
        });

        /*
         * The forward pointer, added once `stages` exists.
         *
         * Composite like every other cross-table reference here, so a workflow
         * cannot point at a stage in another team. It could only be added
         * after both tables were created — see the note on `current_stage_id`
         * above.
         */
        Schema::table('workflows', function (Blueprint $table): void {
            $table->foreign(['team_id', 'current_stage_id'])
                ->references(['team_id', 'id'])
                ->on('stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropForeign(['team_id', 'current_stage_id']);
        });

        Schema::dropIfExists('tasks');
        Schema::dropIfExists('gates');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('workflows');
    }
};
