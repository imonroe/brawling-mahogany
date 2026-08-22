<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration conventions, expressed once so the next forty tables inherit them.
 *
 * See docs/adr/0001-data-and-persistence-conventions.md.
 */
final class BlueprintMacros
{
    public static function register(): void
    {
        /*
         * The opening of every business table: a ULID key, the tenant column,
         * timestamps, and soft deletes.
         *
         * `team_id` is not nullable and not optional. PRD §8.2 puts it on every
         * business table, and a table that skips it is a table the global scope
         * cannot protect.
         *
         * `$constrained` adds the database's own half of that guarantee — the
         * second enforcement layer in ADR 0002. It defaulted to false in
         * Slice 0 only because `teams` did not exist yet; Slice 1 created it,
         * so the default flipped exactly as ADR 0001 said it would.
         */
        Blueprint::macro('productDefaults', function (bool $teamScoped = true, bool $constrained = true): void {
            /** @var Blueprint $this */
            $this->ulid('id')->primary();

            if ($teamScoped) {
                $column = $this->foreignUlid('team_id');

                if ($constrained) {
                    // A team is deleted by purging its data (PRD §9), so the
                    // cascade is the correct behaviour rather than a
                    // convenience.
                    $column->constrained('teams')->cascadeOnDelete();
                } else {
                    $column->index();
                }

                /*
                 * The target a composite foreign key points at.
                 *
                 * ADR 0002's second layer wants `(team_id, id)` on the child
                 * referencing `(team_id, id)` on the parent, and Postgres will
                 * only accept that if the parent carries a unique index over
                 * both columns. `id` is already unique on its own, so this
                 * constrains nothing new — it exists so that
                 * `teamScopedForeign()` has something to reference.
                 */
                $this->unique(['team_id', 'id']);
            }

            $this->timestamps();
            $this->softDeletes();
        });

        /*
         * A foreign key that carries the tenant with it.
         *
         * ADR 0002, layer 2: "a `task` pointing at a `stage` in another team is
         * then not merely unlikely, it is unrepresentable." A plain
         * `foreignUlid('stage_id')->constrained()` cannot say that; a composite
         * key over `(team_id, stage_id)` can.
         *
         * The child's own `team_id` does double duty — it scopes the row *and*
         * it is half of every composite key out of it — which is what makes a
         * cross-tenant pointer a database error rather than a code review.
         */
        Blueprint::macro('teamScopedForeign', function (string $column, string $table, bool $nullable = false) {
            /** @var Blueprint $this */
            $this->foreignUlid($column)->nullable($nullable);

            $this->foreign(['team_id', $column])
                ->references(['team_id', 'id'])
                ->on($table)
                ->cascadeOnDelete();

            return $this->index([$column]);
        });

        /*
         * Money is integer cents, never a float. A transaction value is
         * somebody's house; binary floating point is not an acceptable way to
         * hold it.
         */
        Blueprint::macro('money', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            return $this->bigInteger($column)->nullable($nullable);
        });

        /*
         * Config columns are JSONB. Postgres was chosen partly for this
         * (PRD §8.1): JSONB indexes and constrains, JSON text does neither.
         */
        Blueprint::macro('config', function (string $column = 'config', bool $nullable = true) {
            /** @var Blueprint $this */
            return $this->jsonb($column)->nullable($nullable);
        });
    }
}
