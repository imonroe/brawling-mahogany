<?php

declare(strict_types=1);

use App\Enums\DocumentVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a document may be seen by, and whether anyone looked inside it
 * (PRD §4.6 F6.3, F6.7 · issues #98, #100).
 *
 * ## Visibility defaults to internal in three places, and they must agree
 *
 * F6.3: *"internal by default, client-visible is explicit"* — the same rule
 * notes carry, because the cost of the two mistakes is not symmetric. A
 * document that should have been shared and was not is a conversation; one
 * that should not have been shared and was is not recoverable, and this table
 * holds inspection reports and disclosures about somebody's house.
 *
 * So the default is on the column as well as in `DocumentVisibility` and in
 * `DocumentStorage`. A default that lives only in the form is a default the
 * next caller does not have — which is the shape of the F5.7 window that
 * shipped set by a migration and by nothing else.
 *
 * ## Three scan states, not two, and the third is the point
 *
 * `scan_state` is `clean`, `not_scanned`, or nothing yet for a row that
 * predates this. **There is deliberately no `refused` value**: a refused
 * upload never becomes a row at all — it is discarded before anything reaches
 * permanent storage (PRD §4.6, #100 item 2) — so a `refused` row could only
 * ever be a lie about what is on the disk.
 *
 * `not_scanned` exists because PRD §14.1 Q6 turns on it. This application has
 * no OCR, so an image cannot be looked inside; recording that as `clean` would
 * be the *"guarantee that is not there"* Q6 warns about, on the row, forever.
 * A screen that shows a scan result has to be able to say *"nobody looked"*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('visibility')->default(DocumentVisibility::Internal->value);

            $table->string('scan_state')->nullable();
            $table->timestamp('scanned_at')->nullable();
        });

        DB::statement(sprintf(
            "ALTER TABLE documents ADD CONSTRAINT documents_visibility_check CHECK (visibility IN ('%s'))",
            implode("','", array_column(DocumentVisibility::cases(), 'value')),
        ));

        /*
         * The same shape the category constraint uses, and the same argument:
         * an enum in PHP is a rule the application follows, and a CHECK is a
         * rule the database enforces against every writer — including a
         * seeder, a console tinker, and whatever a later slice adds.
         */
        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_scan_state_check CHECK (scan_state IS NULL OR scan_state IN ('clean', 'not_scanned'))",
        );

        /*
         * Everything already here is a property photograph from #63, uploaded
         * before there was a scan to run. `not_scanned` is the truthful value
         * for it — the alternative is backfilling `clean` for files nobody
         * looked at, which is precisely the lie the third state exists to
         * avoid.
         */
        DB::table('documents')->update([
            'scan_state' => 'not_scanned',
            'scanned_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['visibility', 'scan_state', 'scanned_at']);
        });
    }
};
