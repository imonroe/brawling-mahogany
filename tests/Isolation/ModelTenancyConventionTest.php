<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The isolation suite's first test, and the one that has a job before any
 * business table exists.
 *
 * PRD §8.2 puts `team_id` on every business table and a global scope on every
 * business model. The trait and the cross-tenant assertions land with tenancy
 * in Slice 1 (epic #2); until then this test makes the decision unskippable:
 * a new model is either tenant-scoped or explicitly recorded here as
 * team-agnostic, and adding one without deciding fails the build.
 *
 * See docs/adr/0002-multi-tenancy-enforcement.md.
 */

/**
 * Models that legitimately carry no `team_id`.
 *
 * Every entry needs a reason. "It doesn't have one yet" is not a reason —
 * that is the case this test exists to catch.
 */
const TEAM_AGNOSTIC_MODELS = [
    // One record per human, shared across teams. Team-private data about a
    // person lives on `team_memberships` (PRD §6.2).
    App\Models\User::class,
];

function appModels(): array
{
    $directory = app_path('Models');

    if (! is_dir($directory)) {
        return [];
    }

    return collect(scandir($directory))
        ->filter(fn (string $file): bool => Str::endsWith($file, '.php'))
        ->map(fn (string $file): string => 'App\\Models\\'.Str::before($file, '.php'))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

it('records a tenancy decision for every model', function (): void {
    $undecided = collect(appModels())
        ->reject(fn (string $class): bool => in_array($class, TEAM_AGNOSTIC_MODELS, true))
        ->reject(function (string $class): bool {
            // Slice 1 replaces this with the BelongsToTeam trait check.
            return in_array('team_id', (new $class)->getFillable(), true)
                || array_key_exists('team_id', (new $class)->getCasts());
        });

    expect($undecided->all())->toBe(
        [],
        'Every model is either tenant-scoped or listed in TEAM_AGNOSTIC_MODELS with a reason.',
    );
});
