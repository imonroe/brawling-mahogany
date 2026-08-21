<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Enums\ContactType;
use App\Enums\DealState;
use App\Enums\DocumentCategory;
use App\Enums\ExtractedFieldReviewState;
use App\Enums\GateState;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\RestrictedDocumentCategory;
use App\Enums\StageState;
use App\Enums\TaskState;
use App\Enums\WorkflowState;

/**
 * The enums and the documents cannot silently diverge.
 *
 * PRD §6.3 owns the lookup values; Information Architecture §8 owns the state
 * vocabulary. Both are markdown tables in this repository, so this test reads
 * them and compares. Editing one without the other fails the build, which is
 * the whole point — issue #38: "a doc change and a code change cannot silently
 * diverge".
 */

/** The values column of a PRD §6.3 row, split on commas. */
function documented_prd_lookup_values(string $lookup): array
{
    $document = file_get_contents(base_path('docs/Product Requirements Document.md'));

    $section = str_contains($document, '### 6.3 Lookup values')
        ? explode('### 6.3 Lookup values', $document)[1]
        : '';

    expect($section)->not->toBe('', 'PRD §6.3 Lookup values section is missing.');

    // Rows look like: | Property type | Single Family, Multi Family, … |
    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with(trim($line), '|')) {
            continue;
        }

        $cells = array_map(trim(...), array_slice(explode('|', $line), 1, -1));

        if (count($cells) < 2) {
            continue;
        }

        $name = trim(str_replace('*', '', $cells[0]));

        if (mb_strtolower($name) !== mb_strtolower($lookup)) {
            continue;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $cells[1]))));
    }

    throw new RuntimeException("PRD §6.3 has no row for [{$lookup}].");
}

/**
 * Code => UI label pairs from an Information Architecture §8 sub-section.
 *
 * The document uses two shapes, and both are read here: a table whose first
 * cell is a backticked code, and an inline run of `code` → Label pairs
 * separated by a middle dot.
 */
function documented_ia_state_vocabulary(string $heading): array
{
    $document = file_get_contents(base_path('docs/Information Architecture.md'));

    $states = explode('## 8. State vocabulary', $document)[1] ?? '';
    $states = explode("\n## ", $states)[0];

    $section = explode("### {$heading}", $states)[1] ?? '';
    $section = explode('### ', $section)[0];

    expect($section)->not->toBe('', "IA §8 has no [{$heading}] sub-section.");

    $pairs = [];

    foreach (explode("\n", $section) as $line) {
        $line = trim($line);

        if (str_starts_with($line, '|')) {
            $cells = array_map(trim(...), array_slice(explode('|', $line), 1, -1));

            if (count($cells) >= 2 && preg_match('/^`([a-z_]+)`$/', $cells[0], $match) === 1) {
                $pairs[$match[1]] = $cells[1];
            }

            continue;
        }

        if (str_contains($line, '→')) {
            foreach (explode('·', $line) as $pair) {
                if (preg_match('/`([a-z_]+)`\s*→\s*([^(·]+)/u', $pair, $match) === 1) {
                    $pairs[$match[1]] = trim($match[2]);
                }
            }
        }
    }

    return $pairs;
}

dataset('lookups', [
    'property type' => ['Property type', PropertyType::class],
    'property status' => ['Property status', PropertyStatus::class],
    'contact type' => ['Contact type', ContactType::class],
    'participant role' => ['Participant role', ParticipantRole::class],
    'document category' => ['Document category', DocumentCategory::class],
    'restricted categories' => ['Restricted (refused) categories', RestrictedDocumentCategory::class],
]);

it('matches the lookup values in PRD §6.3', function (string $lookup, string $enum): void {
    expect($enum::labels())->toBe(documented_prd_lookup_values($lookup));
})->with('lookups');

dataset('state vocabularies', [
    'deal' => ['Deal', DealState::class],
    'workflow' => ['Workflow', WorkflowState::class],
    'stage' => ['Stage', StageState::class],
    'task' => ['Task', TaskState::class],
    'gate' => ['Gate', GateState::class],
    'person' => ['Person lifecycle', PersonLifecycleState::class],
    'automation' => ['Automation / message', AutomationState::class],
    'extracted field' => ['Extracted field', ExtractedFieldReviewState::class],
]);

it('matches the state vocabulary in IA §8', function (string $heading, string $enum): void {
    $documented = documented_ia_state_vocabulary($heading);

    expect($enum::options())->toBe($documented);
})->with('state vocabularies');

it('keeps every enum value in snake_case', function (): void {
    $enums = [
        DealState::class, WorkflowState::class, StageState::class, TaskState::class,
        GateState::class, PersonLifecycleState::class, AutomationState::class,
        ExtractedFieldReviewState::class, PropertyType::class, PropertyStatus::class,
        ContactType::class, ParticipantRole::class, DocumentCategory::class,
        RestrictedDocumentCategory::class,
    ];

    foreach ($enums as $enum) {
        foreach ($enum::values() as $value) {
            expect($value)->toBeSnakeCase();
        }
    }
});

it('keeps the refusal list out of the selectable document categories', function (): void {
    // PRD §4.6 F6.2: restricted categories are refused outright. They must
    // never appear in a picker, so the two sets share no values at all.
    expect(array_intersect(DocumentCategory::values(), RestrictedDocumentCategory::values()))
        ->toBe([]);

    foreach (RestrictedDocumentCategory::cases() as $case) {
        // A refusal that only prohibits reads as a bug. Each names where the
        // document belongs instead.
        expect($case->refusalReason())->not->toBe('');
    }
});
