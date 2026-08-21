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
         */
        Blueprint::macro('productDefaults', function (bool $teamScoped = true): void {
            /** @var Blueprint $this */
            $this->ulid('id')->primary();

            if ($teamScoped) {
                $this->foreignUlid('team_id')->index();
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
