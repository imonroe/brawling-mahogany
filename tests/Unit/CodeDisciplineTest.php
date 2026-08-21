<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Two project rules that a reviewer would have to remember, held mechanically
 * instead.
 *
 *  1. PRD §9: "No PII in logs, ever." App\Logging\RedactPii is the last line of
 *     defence; this is the first — a log message is a constant, and everything
 *     variable goes in the context array where the scrubber can see its key.
 *
 *  2. CLAUDE.md and IA §12: the superseded vocabulary never appears in code.
 *     "Always use the current terms below in code, routes, and UI — never the
 *     superseded ones, even if you see them in older doc passages."
 */

/**
 * @return list<string>
 */
function sourceFiles(array $directories, array $extensions): array
{
    $finder = (new Finder)
        ->files()
        ->in(array_map(fn (string $path): string => base_path($path), $directories))
        ->name(array_map(fn (string $extension): string => '*.'.$extension, $extensions));

    return array_values(array_map(
        fn ($file): string => $file->getRelativePathname(),
        iterator_to_array($finder),
    ));
}

it('never interpolates a value into a log message', function (): void {
    $offenders = [];

    $finder = (new Finder)->files()->in([base_path('app')])->name('*.php');

    foreach ($finder as $file) {
        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $number => $line) {
            // Log::info("Sent to {$person->email}") and its friends. A model
            // attribute in a message string is how PII reaches a log.
            if (preg_match('/(Log::(emergency|alert|critical|error|warning|notice|info|debug)|logger\(\)->\w+)\(\s*["\'][^"\']*[${]/', $line) === 1) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Log a constant message and pass values in the context array, where the scrubber can see their keys.',
    );
});

it('never uses the superseded vocabulary', function (): void {
    // IA §12 carries the full rename map. `is_milestone` and `milestone_label`
    // are the *new*, narrower meaning and are allowed; the old table names and
    // the old client-portal wording are not.
    $banned = [
        '/\bprojects\b/i',
        '/\bproject_(id|type|types|participants|property)\b/i',
        '/\bmilestones\b/i',
        '/\bmilestone_templates\b/i',
        '/\bclient_portal\b/i',
        '/\bportal_user\b/i',
    ];

    $offenders = [];

    foreach (['app', 'database', 'routes', 'config'] as $directory) {
        $finder = (new Finder)->files()->in([base_path($directory)])->name('*.php');

        foreach ($finder as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach ($banned as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = $directory.'/'.$file->getRelativePathname().' matches '.$pattern;
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Use the current terms from Information Architecture §2.');
});

it('keeps the page components mirroring the routes in PascalCase', function (): void {
    // IA §6: /deals renders Pages/Deals/Index.vue.
    $pages = sourceFiles(['resources/js/pages'], ['vue']);

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        foreach (explode('/', $page) as $segment) {
            expect($segment)->toMatch('/^[A-Z][A-Za-z0-9]*(\.vue)?$/');
        }
    }
});
