<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
