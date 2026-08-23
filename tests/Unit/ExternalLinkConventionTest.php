<?php

declare(strict_types=1);

use App\Models\Concerns\HasExternalLinks;
use App\Models\ExternalLink;
use App\Models\Property;
use Symfony\Component\Finder\Finder;

/**
 * The two lists that describe what can carry a link (issue #61).
 *
 * `HasExternalLinks` is what a model uses to opt in; `ExternalLink::LINKABLE`
 * is what the tenancy guard checks. They have to be the same set, and nothing
 * about either one makes that true on its own — the trait cannot refuse a
 * `linkable_type` written straight into the column, and the constant cannot
 * give a model a relation.
 *
 * A model in one and not the other is a real defect either way round: in the
 * trait only, its links save and the guard refuses them; in the constant only,
 * the guard permits a target with no way to read its own links back.
 */
function modelsUsingExternalLinks(): array
{
    $models = [];

    foreach ((new Finder)->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
        $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

        if (class_exists($class) && in_array(HasExternalLinks::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

it('keeps the allowlist and the trait in step', function (): void {
    $allowlist = ExternalLink::LINKABLE;
    sort($allowlist);

    expect(modelsUsingExternalLinks())->toBe($allowlist);
});

it('lists only team-scoped models that soft-delete', function (): void {
    foreach (ExternalLink::LINKABLE as $class) {
        $traits = class_uses_recursive($class);

        // The guard reads the target's `team_id`. A model without one has no
        // answer to give, and the check would pass every foreign row.
        expect(in_array(App\Models\Concerns\BelongsToTeam::class, $traits, true))
            ->toBeTrue("[{$class}] carries no team.");

        // And `HasExternalLinks`' own `deleting` hook calls
        // `isForceDeleting()`. Without soft deletes that is a fatal, and the
        // links would outlive their record with nothing to purge them:
        // polymorphic, so no foreign key reaches them.
        expect(in_array(Illuminate\Database\Eloquent\SoftDeletes::class, $traits, true))
            ->toBeTrue("[{$class}] does not soft-delete.");
    }
});

it('starts with the property, which is the one screen S36 builds', function (): void {
    expect(ExternalLink::LINKABLE)->toContain(Property::class);
});
