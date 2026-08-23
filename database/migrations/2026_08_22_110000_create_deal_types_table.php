<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deal types (PRD §4.3 F3.1, §6.2, §7.6 · Screen Inventory S76 · issue #58).
 *
 * PRD §7.6 corrects the rough data model, which drew Deal-to-Deal Type as
 * one-to-one. It is **many-to-one against a lookup**: many deals share one
 * type, which is the only shape that lets a team add "Land Sale" without a
 * migration.
 *
 * `team_id` is nullable here, and it is the same shape `roles` uses: a null
 * team means a system default every team gets, and a set one means a type this
 * team wrote for itself. That is why this table cannot use `productDefaults()`
 * — the macro's `team_id` is deliberately not nullable, because on a *business*
 * table a null tenant is a leak. On a lookup carrying no customer data it is
 * the mechanism.
 *
 * `archived_at` rather than a delete, per S76's in-use warning. A type live
 * deals point at cannot be removed without orphaning them, so archiving keeps
 * the existing deals labelled and takes the type out of every picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();

            $table->string('name');

            // buy · sell · rent · other. Drives which workflow templates are
            // offered and whether the Offers tab exists at all (IA §5.2).
            $table->string('side');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'archived_at']);

            /*
             * The composite target, matching `productDefaults()`.
             *
             * `deals` points at this table with a composite key, and Postgres
             * needs a unique index over the referenced pair. It is nullable on
             * this side, which Postgres allows in a unique index and which a
             * composite FK from a NOT NULL `deals.team_id` can still satisfy —
             * a team's deal can only reference that team's type.
             */
            $table->unique(['team_id', 'id']);
        });

        /*
         * A team cannot have two live types with the same name; two teams can.
         * Partial, so an archived type does not block reusing its name — the
         * whole point of archiving is that the name is free again.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_types_team_name_unique
                ON deal_types (team_id, lower(name))
                WHERE deleted_at IS NULL AND archived_at IS NULL AND team_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deal_types_system_name_unique
                ON deal_types (lower(name))
                WHERE deleted_at IS NULL AND archived_at IS NULL AND team_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_types');
    }
};
