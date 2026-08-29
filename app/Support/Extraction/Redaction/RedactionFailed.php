<?php

declare(strict_types=1);

namespace App\Support\Extraction\Redaction;

use RuntimeException;

/**
 * The redactor could not finish, so nothing may leave.
 *
 * `preg_match_all` returns **false** on a backtrack limit or a malformed UTF-8
 * sequence, and both are reachable from a document somebody uploaded. The
 * tempting handling is to carry on with what we had — `?? $subject`, or simply
 * treating a false return as "no matches" — and that is precisely the bug: it
 * hands the provider the text with the identifiers still in it, silently, on
 * the one input weird enough to have broken the regex.
 *
 * (This named `preg_replace_callback` and its **null** return for a round,
 * which was true of an earlier `Redactor::replace()`. The argument never
 * changed and the mechanism did — worth correcting rather than leaving,
 * because this is the class whose whole point is that the mechanism decides
 * which way a failure falls.)
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
