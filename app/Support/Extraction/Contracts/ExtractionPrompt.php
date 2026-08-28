<?php

declare(strict_types=1);

namespace App\Support\Extraction\Contracts;

use App\Enums\ExtractionKind;

/**
 * What to ask the model, and which version of the asking this was.
 *
 * ## `version()` is the reason this is an object
 *
 * F10.4 stores `prompt_version` on every extraction, and #118 turns it into
 * the axis the regression harness reports against: *"record results against
 * `prompt_version` so the history is queryable."* A prompt held as a string
 * constant would make that column a lie the first time somebody improved a
 * sentence without thinking of it as a version change.
 *
 * So the rule is: **editing `instructions()` means bumping `version()`**, and
 * `tests/Unit/ExtractionPromptVersionTest.php` holds it by hashing the text.
 * That test fails on any edit, which is the point — it is a prompt to bump the
 * version, not an assertion that the words are right.
 */
interface ExtractionPrompt
{
    public function kind(): ExtractionKind;

    /** Stored on `extractions.prompt_version`. Bump on any edit to the text. */
    public function version(): string;

    /** The system instruction: what the model is, and what it must not do. */
    public function system(): string;

    /** The task, wrapped around the document's redacted text. */
    public function instructions(string $documentText): string;
}
