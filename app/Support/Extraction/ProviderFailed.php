<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use RuntimeException;

/**
 * The provider call did not produce a usable answer.
 *
 * #115: *"Failure is a state, not an exception the user meets as a 500."* This
 * exception exists so the worker can turn it into `extractions.state = failed`
 * with words somebody can act on, and it carries a **`reasonCode`** rather than
 * a free-text reason for the CLAUDE.md rule one layer down: `Redactor`'s
 * `SENSITIVE_KEY_PARTS` holds `reason`, so a diagnostic logged under that key
 * reaches the operator as `[redacted]`. Enumerated codes pass, because
 * `ALLOWED_KEY_PATTERNS` passes `_code$`.
 *
 * `$message` is what a person reads on S65 and is written for them; the code is
 * what goes in the log and on the row.
 */
final class ProviderFailed extends RuntimeException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly bool $isRetryable,
    ) {
        parent::__construct($message);
    }

    /** No API key, or a driver that cannot call anything. */
    public static function notConfigured(): self
    {
        return new self(
            'provider_not_configured',
            'Extraction is not switched on for this installation yet.',
            isRetryable: false,
        );
    }

    /** A timeout, a 5xx, a connection reset — worth another attempt. */
    public static function unavailable(): self
    {
        return new self(
            'provider_unavailable',
            'The extraction service did not answer. This will be tried again.',
            isRetryable: true,
        );
    }

    /** A 4xx that another attempt will reproduce exactly. */
    public static function refused(int $status): self
    {
        return new self(
            'provider_refused_'.$status,
            'The extraction service refused this document.',
            isRetryable: false,
        );
    }

    /**
     * The call succeeded and the answer was not the shape asked for.
     *
     * Deliberately **not** retryable. A model that returned prose instead of
     * JSON will do it again, and the money is the point: PRD §12.3 caps cost
     * per deal at $2, and four attempts at an unparseable answer is four times
     * the price of one.
     */
    public static function unreadableResponse(): self
    {
        return new self(
            'provider_response_unreadable',
            'The extraction service answered in a form this app could not use.',
            isRetryable: false,
        );
    }
}
