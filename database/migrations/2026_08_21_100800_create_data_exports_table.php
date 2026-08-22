<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team data export (PRD §9 Data export · Screen Inventory S79).
 *
 * PRD §10: CCPA/CPRA and similar create access obligations that export and
 * deletion cover. Built now, over three tables, it is a morning's work;
 * retrofitted over forty tables it is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table): void {
            $table->productDefaults();
            $table->foreignUlid('requested_by_person_id')->nullable()->constrained('people')->nullOnDelete();

            $table->string('state');
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // The archive is a copy of the team's entire record set sitting in
            // object storage. It expires, and the expired file is deleted.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();

            $table->index(['team_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
