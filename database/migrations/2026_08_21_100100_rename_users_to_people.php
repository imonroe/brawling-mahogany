<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users` becomes `people` (PRD §4.2 F2.1, §6.2 · IA §2 · ADR 0001).
 *
 * ADR 0001 already called `users` *"the precursor to `people`"*. This is that
 * promise being kept, and it settles issue #18 the way the PRD proposed:
 * **one row per human, shared across teams**, with everything a team knows
 * privately about that human living on `team_memberships` instead.
 *
 * The consequence that drives the rest of the schema: **credentials are
 * optional**. Most people in this product — clients, vendors, opposing agents
 * — never log in, and a null password must never authenticate (issue #43).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'people');

        // The partial index came over with the table but kept its old name.
        DB::statement('ALTER INDEX users_email_unique RENAME TO people_email_unique');

        Schema::table('people', function (Blueprint $table): void {
            // IA §10 formats a person as First Last and sorts by last, which
            // a single `name` column cannot do. `resources/js/lib/formatters.ts`
            // has expected these two fields since Slice 0.
            $table->string('first_name')->after('id')->nullable();
            $table->string('last_name')->after('first_name')->nullable();
            $table->string('phone')->after('email')->nullable();

            // PRD §4.1 F1.5. The bypass this flag unlocks is explicit and
            // audited (ADR 0002), never an absent scope.
            $table->boolean('is_super_admin')->after('phone')->default(false);
        });

        // Split the existing single name on the first space: everything before
        // it is the given name, the remainder is the family name. Nobody has
        // signed up yet, so this is tidiness rather than a data migration.
        DB::statement(<<<'SQL'
            UPDATE people
            SET first_name = split_part(name, ' ', 1),
                last_name = NULLIF(substr(name, strpos(name, ' ') + 1), split_part(name, ' ', 1))
            WHERE name IS NOT NULL
        SQL);

        DB::statement("UPDATE people SET first_name = '' WHERE first_name IS NULL");

        Schema::table('people', function (Blueprint $table): void {
            $table->string('first_name')->nullable(false)->change();
            $table->dropColumn('name');

            // The whole point of the rename: a person without a login.
            $table->string('password')->nullable()->change();
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropIndex(['last_name']);
            $table->string('name')->nullable();
        });

        DB::statement("UPDATE people SET name = trim(both ' ' from concat(first_name, ' ', coalesce(last_name, '')))");

        Schema::table('people', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn(['first_name', 'last_name', 'phone', 'is_super_admin']);
            $table->string('password')->nullable(false)->change();
        });

        DB::statement('ALTER INDEX people_email_unique RENAME TO users_email_unique');

        Schema::rename('people', 'users');
    }
};
