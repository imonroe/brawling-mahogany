<?php

declare(strict_types=1);

namespace App\Support\Extraction\Redaction;

use RuntimeException;

/**
 * The redactor could not finish, so nothing may leave.
 *
 * `preg_replace_callback` returns null on a backtrack limit or a malformed
 * UTF-8 sequence, and both are reachable from a document somebody uploaded.
 * The tempting handling is `?? $subject` — carry on with what we had — and
 * that is precisely the bug: it hands the provider the text with the
 * identifiers still in it, silently, on the one input weird enough to have
 * broken the regex.
 *
 * So this is thrown, the extraction fails visibly, and the operator sees a
 * rule name. PRD §9's *"no document reaches a third-party model without
 * redaction"* has no partial form.
 */
final class RedactionFailed extends RuntimeException
{
    private function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }

    public static function onRule(string $rule): self
    {
        /*
         * The rule name, never the text. A message carrying the passage that
         * broke the pattern would put the unredacted content in a log, which
         * is the same disclosure by a shorter route.
         */
        return new self(
            $rule,
            'Could not finish redacting this document, so it was not sent. Rule: '.$rule.'.',
        );
    }
}
