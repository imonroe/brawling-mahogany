<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact import (PRD §4.2 F2.8 · Screen Inventory S33).
 *
 * F2.8: *"Emily named the absence of this as an obvious failing in the
 * competitor. Nobody retypes a client list."*
 *
 * One row per import attempt, because the import is a queued job and the
 * screen has to be able to show its progress, its result, and — the part that
 * makes it useful rather than infuriating — exactly which rows failed and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table): void {
            $table->productDefaults();
            $table->foreignUlid('requested_by_person_id')->nullable()->constrained('people')->nullOnDelete();

            $table->string('source');
            $table->string('state');
            $table->string('original_filename')->nullable();
            $table->string('disk_path')->nullable();

            // Remembered so a repeat import of the same export does not need
            // the columns mapped again (S33's field-mapping state).
            $table->config('column_mapping');

            // Parsed rows, held between the preview and the commit so the
            // person can change their mind before anything is written.
            $table->config('preview');
            $table->config('summary');

            /*
             * "Row 340 is malformed — import the other 339 and report row 340
             * specifically." Row numbers and reasons only: a failure report is
             * a place PII leaks if the offending value is copied into it.
             */
            $table->config('failures');

            $table->timestamp('completed_at')->nullable();

            $table->index(['team_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
