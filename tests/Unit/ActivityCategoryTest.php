<?php

declare(strict_types=1);

use App\Enums\ActivityCategory;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * S12's filter cannot silently stop covering the product (issue #81).
 *
 * `ActivityCategory` groups event types by the half before the dot, which is
 * what lets a `task.completed` added in Slice 3 land in a group without this
 * enum changing — *provided somebody claimed the prefix*. Nothing about an
 * unclaimed one is visible at runtime: the row still appears under All, and
 * simply never appears under any other tab. That is a filter that lies, and it
 * would be found by a customer rather than by a reviewer.
 *
 * So the source is read. Every `eventType:` literal in `app/` has to carry a
 * prefix some category claims.
 */

/** Every event type the application writes, read out of the source. */
function recordedEventTypes(): array
{
    $types = [];

    $finder = (new Finder)->files()->in([base_path('app')])->name('*.php');

    foreach ($finder as $file) {
        preg_match_all(
            "/eventType:\s*'([a-z_]+\.[a-z_]+)'/",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $type) {
            $types[$type] = true;
        }
    }

    return array_keys($types);
}

it('finds the event types the application writes', function (): void {
    // The guard on the guard: a regex that stopped matching would make every
    // assertion below pass over an empty list.
    expect(recordedEventTypes())
        ->toContain('contact.logged')
        ->toContain('stage.advanced')
        ->and(count(recordedEventTypes()))->toBeGreaterThan(10);
});

it('claims the prefix of every event type the application writes', function (): void {
    $claimed = ActivityCategory::everyPrefix();

    $unclaimed = [];

    foreach (recordedEventTypes() as $type) {
        $prefix = Str::before($type, '.');

        if (! in_array($prefix, $claimed, strict: true)) {
            $unclaimed[] = $type;
        }
    }

    expect($unclaimed)->toBe(
        [],
        'Add the prefix to an App\Enums\ActivityCategory case, or the feed filter hides these rows under every tab but All.',
    );
});

it('gives All no prefixes at all', function (): void {
    // All is the absence of a filter, not a group. A prefix list on it would
    // become a second, worse copy of the union of the others.
    expect(ActivityCategory::All->prefixes())->toBe([]);
});

it('keeps every category’s empty copy specific to it', function (): void {
    // IA §10: say what belongs here. One shared sentence across five tabs
    // tells somebody nothing about the tab they are looking at.
    $messages = array_map(
        fn (ActivityCategory $category): string => $category->emptyMessage(),
        ActivityCategory::cases(),
    );

    expect(array_unique($messages))->toHaveCount(count($messages));
});
