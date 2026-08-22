<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one unified timeline (PRD §4.9 F9.4, §6.2, §7.7 · IA §2).
 *
 * PRD §7.7 found three overlapping audit entities — Contact Log Entry, Action
 * Log, and Action Instance — all answering "what happened and when." This is
 * the fix: **one polymorphic table**, with `action_instances` keeping
 * automation execution state and `audit_log` keeping the security record.
 * Two purposes, two tables, not four.
 *
 * IA §11 calls this **Activity**, never History, Log, Feed, or Audit — *Audit*
 * means the security log, which has different retention and different readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table): void {
            $table->productDefaults();

            // Polymorphic: a person, a deal, a stage, a document. The subject
            // is whatever the event happened *to*.
            $table->string('subject_type');
            $table->ulid('subject_id');

            // Nullable because the actor is sometimes the system: a scheduled
            // automation has no human behind it.
            $table->foreignUlid('actor_person_id')->nullable()->constrained('people')->nullOnDelete();

            $table->string('event_type');
            $table->string('source');
            $table->timestamp('occurred_at');
            $table->string('summary');
            $table->config('payload');

            /*
             * The client boundary.
             *
             * The status page (Slice 4) reads this table filtered to visible
             * events. An event is internal unless somebody deliberately says
             * otherwise, so the default is false and stays false.
             */
            $table->boolean('is_client_visible')->default(false);

            // The two queries that matter at PRD §9's 500,000-event target:
            // one subject's timeline, and one team's recent activity.
            $table->index(['team_id', 'subject_type', 'subject_id', 'occurred_at']);
            $table->index(['team_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
