<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Source-file lookups for the tests that hold project rules.
 *
 * A class rather than a function at file scope: two test files declaring the
 * same global helper is a fatal error, not a test failure, and the failure
 * lands nowhere near the cause.
 */
final class Sources
{
    /**
     * @param  list<string>  $directories  relative to the project root
     * @param  list<string>  $extensions  without the dot
     * @return list<string> paths relative to each directory
     */
    public static function files(array $directories, array $extensions, array $exclude = []): array
    {
        $finder = (new Finder)
            ->files()
            ->in(array_map(fn (string $path): string => base_path($path), $directories))
            ->name(array_map(fn (string $extension): string => '*.'.$extension, $extensions));

        if ($exclude !== []) {
            $finder->exclude($exclude);
        }

        return array_values(array_map(
            fn ($file): string => $file->getRelativePathname(),
            iterator_to_array($finder),
        ));
    }
}
