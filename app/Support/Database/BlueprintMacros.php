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
         * second enforcement layer in ADR 0002. It defaults to false only
         * because `teams` does not exist until Slice 1; once it does, every
         * business table passes `constrained: true` and the default flips.
         */
        Blueprint::macro('productDefaults', function (bool $teamScoped = true, bool $constrained = false): void {
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
            }

            $this->timestamps();
            $this->softDeletes();
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
