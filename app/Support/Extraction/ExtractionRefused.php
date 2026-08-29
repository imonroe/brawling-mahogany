<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use RuntimeException;

/**
 * Extraction will not be started, and here is the sentence to show.
 *
 * Thrown before any row is written, so a person pressing the button on S65
 * learns immediately rather than watching a job queue and fail. The three
 * reasons get three sentences for the reason #110's expired-link page gives:
 * *"already used"* means press the newer link and *"revoked"* means your agent
 * ended this, and collapsing them into one message costs somebody the action
 * they could have taken.
 */
final class ExtractionRefused extends RuntimeException
{
    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notAvailable(): self
    {
        return new self(
            'provider_not_configured',
            'Extraction is not switched on for this installation yet.',
        );
    }

    public static function capped(SpendDecision $decision): self
    {
        return new self(
            (string) $decision->reasonCode,
            (string) $decision->message,
        );
    }

    /**
     * The document has no words in it.
     *
     * A photograph of a contract, or a PDF with no text layer — PRD assumption
     * A10 is explicit that reading those is **unverified**, and this product
     * has no OCR. Saying so is much better than queueing a job that spends a
     * provider call on an empty string and comes back with nothing, which is
     * indistinguishable on a screen from a contract that had no dates in it.
     */
    public static function unreadable(): self
    {
        return new self(
            'document_has_no_text',
            'There are no words in this file to read. A photograph or a scan without a text layer '
                .'cannot be read here — the dates will need entering by hand.',
        );
    }

    public static function alreadyRunning(): self
    {
        return new self(
            'extraction_already_running',
            'This document is already being read. The results will appear when it finishes.',
        );
    }
}
