<?php

declare(strict_types=1);

namespace App\Support\Extraction\Contracts;

use App\Support\Extraction\ProviderResult;
use App\Support\Extraction\Redaction\RedactedDocument;

/**
 * F10.6 — the seam the model provider sits behind.
 *
 * *"Extraction sits behind an interface so the model provider can change
 * without touching the workflow engine."* Two things make that true rather
 * than aspirational, and both are visible in the signature:
 *
 * 1. **The argument is a `RedactedDocument`.** There is no way to implement
 *    this interface that receives unredacted text, so a new provider inherits
 *    F10.5 by construction rather than by its author remembering (#114).
 * 2. **The return is a `ProviderResult`, not a parsed proposal.** A provider's
 *    job ends at "here is what the model said and what it cost". Turning that
 *    into `extracted_fields` is `ReadProposals`' job, and keeping the two apart
 *    is what stops a second provider quietly shipping a second interpretation
 *    of confidence.
 *
 * Nothing downstream of this — the key dates cascade, the workflow engine, the
 * review screens — knows which implementation ran. `extractions.provider` and
 * `extractions.model` record which one did, after the fact.
 */
interface ExtractionProvider
{
    /** Stored on `extractions.provider`. Stable across model changes. */
    public function name(): string;

    /** Stored on `extractions.model`. */
    public function model(): string;

    /**
     * Whether a call would reach anything.
     *
     * Separate from `extract()` throwing, because the answer is needed *before*
     * a row is written — S65 tells somebody extraction is unavailable rather
     * than queueing work that will fail one worker later.
     */
    public function isConfigured(): bool;

    /**
     * @throws \App\Support\Extraction\ProviderFailed
     */
    public function extract(RedactedDocument $document, ExtractionPrompt $prompt): ProviderResult;
}
