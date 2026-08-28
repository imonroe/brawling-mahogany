<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A team's own monthly extraction ceiling (PRD §14.3, §9 · issue #113).
 *
 * ## Why a nullable override rather than a value on every team
 *
 * The default lives in `config/extraction.php` and applies to everybody. This
 * column is what an operator sets for the one team that needs a different
 * number — a high-volume brokerage on a negotiated price, or a team that has
 * just spent a month's budget in a morning and needs stopping now.
 *
 * Null therefore means *"whatever the configured default is"*, and it means it
 * for the life of the row rather than at the moment the row was written. A
 * column defaulted to the config value at creation time would freeze last
 * year's number onto every team that existed then, and raising the platform
 * default would silently not raise theirs — the trap CLAUDE.md records for
 * `teams.approval_required_until`, running the other way.
 *
 * ## Micros, not cents
 *
 * The same unit as `extractions.cost_micros`, and named so. A cap in cents
 * compared against a spend in micros is a factor of ten thousand, in the
 * direction where the cap never fires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->bigInteger('extraction_monthly_cap_micros')->nullable();
        });

        DB::statement(
            'ALTER TABLE teams ADD CONSTRAINT teams_extraction_cap_not_negative_check '
            .'CHECK (extraction_monthly_cap_micros IS NULL OR extraction_monthly_cap_micros >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_extraction_cap_not_negative_check');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('extraction_monthly_cap_micros');
        });
    }
};
