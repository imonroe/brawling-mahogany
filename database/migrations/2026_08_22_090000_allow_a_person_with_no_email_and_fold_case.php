<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two corrections to `people`, both of them PRD §4.2 F2.1 catching up with
 * itself.
 *
 * **An address is optional.** *"One record per human, login credentials
 * optional"* — and for a vendor with a phone number and no email, or a
 * co-agent whose address nobody has yet, the column has to accept nothing.
 * It was `NOT NULL` because it came from Laravel's `users` table, where
 * everybody signs in.
 *
 * **An address is one address whatever its capitals.** The unique index was
 * over `email` verbatim, so `Emily@Example.test` and `emily@example.test`
 * were two people. That breaks the shared-record decision (PRD decision log,
 * 2026-08-22) at its foundation: the whole promise is one row per human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        // Fold what is already there before the index insists on it.
        DB::statement('UPDATE people SET email = lower(email) WHERE email IS NOT NULL');

        DB::statement('DROP INDEX IF EXISTS people_email_unique');

        /*
         * Unique among the living, case-insensitively, and only where there
         * is an address at all — Postgres treats NULLs as distinct, which is
         * exactly right here: any number of people may have no email.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX people_email_unique
            ON people (lower(email))
            WHERE deleted_at IS NULL AND email IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS people_email_unique');

        DB::statement('DELETE FROM people WHERE email IS NULL');

        Schema::table('people', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });

        DB::statement('CREATE UNIQUE INDEX people_email_unique ON people (email) WHERE deleted_at IS NULL');
    }
};
