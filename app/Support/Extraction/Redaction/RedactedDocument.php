<?php

declare(strict_types=1);

namespace App\Support\Extraction\Redaction;

/**
 * Text that has been through the redactor, and the only thing a provider takes.
 *
 * ## This type is the enforcement
 *
 * #114: *"No provider call is possible without passing through redaction —
 * **enforced structurally, not by convention**."* `ExtractionProvider::extract()`
 * takes one of these and not a string, so there is no argument you can hand it
 * that has not been redacted. That is the half the type system decides, and it
 * is the half that matters: the failure mode #114 is written about is somebody
 * later adding a provider call that forgets a step, and a forgotten step here
 * is a compile-time impossibility rather than a review catch.
 *
 * The other half — that a `RedactedDocument` cannot be *minted* out of raw
 * bytes — has no type-system answer in PHP, which has no package-private and
 * no friend classes. So the constructor is private, `of()` is `@internal`, and
 * `tests/Unit/RedactionPathTest.php` reads the source and fails when anything
 * but `Redactor` calls it. That is the same shape as `SingleMutationPathTest`
 * and `SingleNotificationWriterTest`, and it is written down here rather than
 * only in the test because a guard nobody knows about is a guard somebody
 * routes around.
 *
 * ## Why the text and not the bytes
 *
 * The original PDF cannot be redacted — masking a run of digits in a
 * compressed content stream means re-encoding the file, and a redactor that
 * rewrites PDFs is a much larger and much more failure-prone thing than the
 * one this product needs. So the pipeline reads the words out with
 * `ReadableText` and sends *those*, which has the useful property that what
 * the provider receives is exactly what is recorded on the `extractions` row.
 * The cost is real and is stated rather than hidden: a scanned contract with
 * no text layer yields nothing, and PRD A10 flags reading those as unverified.
 */
final readonly class RedactedDocument
{
    /**
     * @internal Constructed only by {@see Redactor::redact()}. Held by
     *           `tests/Unit/RedactionPathTest.php`.
     */
    private function __construct(
        public string $text,
        public RedactionReport $report,
    ) {}

    /**
     * @internal Call {@see Redactor::redact()} instead.
     */
    public static function of(string $text, RedactionReport $report): self
    {
        return new self($text, $report);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }
}
