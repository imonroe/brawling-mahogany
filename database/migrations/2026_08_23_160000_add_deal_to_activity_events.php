<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The deal an event belongs to, alongside the thing it happened to (issue #81).
 *
 * `subject_type`/`subject_id` answers *what this happened to*, and that is not
 * the same question as *which deal this belongs on*. S26 is where the two come
 * apart: PRD F2.5 logs a contact *"against a person and optionally a deal"*, so
 * the subject is the person and the deal is context. Without a column for it
 * the deal half is unrepresentable, and issue #81's *"logged contacts appear on
 * the person, the deal, and the feed"* has no second place to appear.
 *
 * It is deliberately not `payload->deal_id`. A deal's timeline (S16) is one of
 * the two queries PRD §9's 500,000-event target is sized for, and a filter
 * inside a JSONB blob is a filter no ordinary index answers.
 *
 * `teamScopedForeign` rather than a plain foreign key, so ADR 0002's second
 * layer covers it: an event in one team pointing at another team's deal is
 * unrepresentable rather than merely unlikely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->teamScopedForeign('deal_id', 'deals', nullable: true);

            // The third query that matters, beside one subject's timeline and
            // one team's recent activity: one deal's timeline.
            $table->index(['team_id', 'deal_id', 'occurred_at']);
        });

        $this->backfillDealSubjects();
        $this->backfillWorkflowSubjects();
    }

    /**
     * Every event already recorded *about a deal* belongs on that deal.
     *
     * Without this the column is only correct for events written after the
     * deploy. `dev` has had deals since #59, so a team that migrated would
     * open S15 and S12's deal filter on a feed that begins the day the column
     * did — the history is still in the table, and every row of it reads as
     * belonging to no deal. `RecordActivity` derives `deal_id` from a deal
     * subject for new rows; this is the same derivation applied backwards.
     *
     * The class name is written out rather than asked of the model. A
     * migration is history: it has to keep meaning what it meant when it ran,
     * and `Deal::getMorphClass()` is a value a later morph map is free to
     * change. Rows written before that change would still hold the FQCN, so
     * the literal is the one that stays true.
     *
     * Joined to `deals` on the team as well as the id, rather than a bare
     * `SET deal_id = subject_id`. The composite foreign key added above would
     * reject a cross-team pair anyway — but it would reject it by failing the
     * migration halfway, and a deploy that stops on one anomalous row is worse
     * than a deploy that leaves that row alone.
     */
    private function backfillDealSubjects(): void
    {
        DB::statement(<<<'SQL'
            update activity_events
               set deal_id = deals.id
              from deals
             where deals.id = activity_events.subject_id
               and deals.team_id = activity_events.team_id
               and activity_events.subject_type = 'App\Models\Deal'
               and activity_events.deal_id is null
        SQL);
    }

    /**
     * And so does every event recorded against one of that deal's workflows.
     *
     * This is the half that is easy to forget, because the deal is one hop
     * away rather than in the row. `stage.advanced`, `milestone.reached`,
     * `workflow.completed` and `workflow.started` are all subjected to the
     * **workflow** — they pass `deal:` explicitly now, but nothing filled the
     * column before it existed.
     *
     * Leaving them out is not a cosmetic gap. S15's activity card reads by
     * `deal_id`, and a stage advance is the single entry a person opening that
     * screen most wants to see; the retention purge's detach step reads the
     * same column to decide what a purged deal takes with it. A null here
     * means an advance that vanishes from its own deal and survives the deal's
     * deletion as an orphan.
     *
     * `workflows.deal_id` is `NOT NULL`, so every matched row yields a deal.
     */
    private function backfillWorkflowSubjects(): void
    {
        DB::statement(<<<'SQL'
            update activity_events
               set deal_id = workflows.deal_id
              from workflows
             where workflows.id = activity_events.subject_id
               and workflows.team_id = activity_events.team_id
               and activity_events.subject_type = 'App\Models\Workflow'
               and activity_events.deal_id is null
        SQL);
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'deal_id', 'occurred_at']);
            $table->dropForeign(['team_id', 'deal_id']);
            $table->dropIndex(['deal_id']);
            $table->dropColumn('deal_id');
        });
    }
};
