<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\Sources;

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

    // CLAUDE.md: the current terms apply "in code, routes, and UI" — so the
    // front end, where the client-facing copy actually lives, is read too.
    $sources = [
        'app' => ['*.php'],
        'database' => ['*.php'],
        'routes' => ['*.php'],
        'config' => ['*.php'],
        'resources/js' => ['*.ts', '*.vue'],
        'resources/views' => ['*.php'],
    ];

    foreach ($sources as $directory => $extensions) {
        $finder = (new Finder)
            ->files()
            ->in([base_path($directory)])
            ->name($extensions)
            // Generated output: Wayfinder's route modules and the shadcn CLI's
            // components are not ours to name.
            ->exclude(['actions', 'routes', 'wayfinder', 'ui']);

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
    $pages = Sources::files(['resources/js/pages'], ['vue']);

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        foreach (explode('/', $page) as $segment) {
            expect($segment)->toMatch('/^[A-Z][A-Za-z0-9]*(\.vue)?$/');
        }
    }
});

/**
 * A **day** is never given an instant on the wire (issue 165).
 *
 * A `date`-cast column carries a calendar day. `toIso8601String()` stamps it
 * midnight **UTC**, and every screen renders in the team's zone — so a task
 * due the 25th drew as *"Aug 24"* for a team in Denver, in the danger tone, a
 * day early. Five payloads had it; the browser half of the fix lives in
 * `formatters.ts`, and the two only work as a pair.
 *
 * The columns come from the models rather than a list here, so casting a new
 * one to `date` puts it under the rule without anybody remembering to.
 */
it('never sends a date-cast column as an instant', function (): void {
    $days = [];

    foreach ((new Finder)->files()->in([base_path('app/Models')])->name('*.php') as $model) {
        preg_match_all(
            "/'(\w+)'\s*=>\s*'date',/",
            (string) file_get_contents($model->getPathname()),
            $matches,
        );

        $days = [...$days, ...$matches[1]];
    }

    // The rule is worthless if it is watching nothing.
    expect($days)->not->toBeEmpty();

    $offenders = [];

    foreach ((new Finder)->files()->in([base_path('app')])->name('*.php') as $file) {
        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $number => $line) {
            foreach (array_unique($days) as $day) {
                if (preg_match('/\b'.preg_quote($day, '/').'\??->toIso8601String\(\)/', $line) === 1) {
                    $offenders[] = $file->getRelativePathname().':'.($number + 1).' ('.$day.')';
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A date column is a day: send it with toDateString(). Only an instant keeps its instant.',
    );
});
