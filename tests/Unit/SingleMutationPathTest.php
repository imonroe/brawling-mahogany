<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Nothing but `AdvanceWorkflow` moves workflow state (PRD §8.3 · issue #68).
 *
 * The Build Plan calls this the architectural keystone and says exactly what
 * it costs to lose:
 *
 * > If a controller ever writes `stages.state` directly, the audit trail, the
 * > automation dispatch, and the gate guarantees all become optional — and
 * > nobody notices until something has been silently skipped.
 *
 * Issue #68's definition of done asks for this test by name: *"proven by a
 * test that asserts no other class writes `stages.state`."*
 *
 * ## Why a source-reading test rather than a runtime one
 *
 * The same reason as `ModelTenancyConventionTest` and
 * `UnscopedQueryConventionTest`. A runtime test can only catch a path it
 * thought to exercise; the bug this guards against is a path nobody thought
 * about — a controller written in Slice 4 that sets `state` because it was
 * quicker than reading this file. Reading the source catches the writer who
 * never ran the test.
 *
 * Slice 1 earned this lesson three times over: a rule enforced at call sites
 * was enforced at *some* call sites, twice in consecutive review rounds. So
 * the rules that matter get a machine.
 */

/**
 * Ways of writing a state column, as regexes.
 *
 * Deliberately not a general "does this file mention state" check, which would
 * be noise. These are the shapes an actual write takes.
 */
const STATE_WRITE_PATTERNS = [
    // ->state = StageState::Complete
    '/->\s*state\s*=(?!=)/',
    // ['state' => …] inside a fill/update/forceFill argument
    '/[\'"]state[\'"]\s*=>/',
    // ->update(['state' => …]) reached through a builder
    '/->\s*transitionTo\s*\(/',
];

/**
 * Files allowed to write workflow state, each with the reason.
 *
 * @var array<string, string>
 */
const SANCTIONED_STATE_WRITERS = [
    'Support/Workflow/AdvanceWorkflow.php' => 'The single mutation path itself (#68).',

    'Support/Workflow/InstantiateWorkflow.php' => 'Sets the opening state of a brand-new workflow. '.
        'There is no stage being left, so there is nothing for the gates to evaluate — routing this '.
        'through AdvanceWorkflow would mean inventing a stage-zero for it to complete.',

    'Models/Workflow.php' => 'Declares its own transition map; writes nothing.',
    'Models/Stage.php' => 'Declares its own transition map; writes nothing.',
    'Models/Deal.php' => 'Declares its own transition map; writes nothing. A deal state is not a '.
        'workflow state — closing a deal is F3.8, not an advance.',
    'Models/Concerns/HasStateMachine.php' => 'The transition mechanism. It says which moves are '.
        'possible; AdvanceWorkflow decides which are permitted.',
];

/**
 * Classes under app/ that touch a workflow-state model at all.
 *
 * @return array<string, string> relative path => contents
 */
function filesTouchingWorkflowState(): array
{
    $found = [];

    foreach ((new Finder)->files()->in([app_path()])->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        $mentionsWorkflowModel = preg_match(
            '/\b(Stage|Workflow)(::class|\s*\$|::query)/',
            $contents,
        ) === 1;

        if ($mentionsWorkflowModel) {
            $found[str_replace('\\', '/', $file->getRelativePathname())] = $contents;
        }
    }

    return $found;
}

it('lets nothing but the advance service write workflow state', function (): void {
    $offenders = [];

    foreach (filesTouchingWorkflowState() as $path => $contents) {
        if (array_key_exists($path, SANCTIONED_STATE_WRITERS)) {
            continue;
        }

        foreach (STATE_WRITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = $path;
                break;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A class outside AdvanceWorkflow writes workflow state. Every advance must go through '
        .'App\Support\Workflow\AdvanceWorkflow so the gates are evaluated, the transition is '
        .'transactional, and the timeline and audit entries are written. Override (#69) and skip '
        .'(#70) are paths *through* that service, not second writers. If this really is a new '
        .'legitimate writer, add it to SANCTIONED_STATE_WRITERS with a reason — and be sure the '
        .'reason is not "it was easier".',
    );
});

it('keeps the advance service free of HTTP concerns', function (): void {
    // F12.5: "the API layer should be designed so a native client can be added
    // without rework." This is the service that would otherwise accrete
    // request handling, and a service that reads a session cannot be called
    // from a queue, a webhook, or a test without one.
    $source = (string) file_get_contents(app_path('Support/Workflow/AdvanceWorkflow.php'));

    foreach (['Illuminate\\Http\\Request', 'Inertia\\', 'session(', 'request(', 'auth()'] as $forbidden) {
        expect($source)->not->toContain(
            $forbidden,
            "AdvanceWorkflow references {$forbidden}. It takes a workflow and an actor and returns "
            .'a result object; the controller adapts (F12.5).',
        );
    }
});

it('names every sanctioned writer as a file that exists', function (): void {
    foreach (array_keys(SANCTIONED_STATE_WRITERS) as $path) {
        expect(file_exists(app_path($path)))->toBeTrue(
            "{$path} is listed as a sanctioned state writer and no longer exists. Remove the entry — "
            .'a stale allow-list reads as coverage it does not have.',
        );
    }
});
