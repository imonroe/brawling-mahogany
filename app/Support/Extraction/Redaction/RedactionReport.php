<?php

declare(strict_types=1);

namespace App\Support\Extraction\Redaction;

/**
 * What was taken out, counted — never what it was.
 *
 * #114's definition of done asks that *"the redacted artefact sent to the
 * provider is recorded, so what was disclosed is knowable after the fact."*
 * The artefact itself answers that: it is the text that left, and it is stored
 * because it is by construction the version with the identifiers gone.
 *
 * This is the other half — what the redactor *removed*, so an operator reading
 * `extractions` can tell "nothing matched" apart from "eleven things matched
 * and were masked". Counts only. A report carrying the values would be a
 * second copy of exactly the data the redactor exists to stop travelling, and
 * it would live in a JSONB column that no `Redactor::SENSITIVE_KEY_PARTS`
 * covers.
 */
final readonly class RedactionReport
{
    /**
     * `$truncated` is deliberately absent.
     *
     * It was here for a round and nothing could ever set it — the redactor has
     * no truncating path, so it was a field that read as a fact and was a
     * constant `false`. `ReadableText::wasPartial()` answers the question it
     * looked like it answered, one layer up, about the *reading* rather than
     * the redaction; if that ever needs recording it belongs on `extractions`
     * beside the text, not here.
     *
     * @param  array<string, int>  $counts  keyed by the redactor's rule names
     */
    private function __construct(
        public array $counts,
    ) {}

    /**
     * @param  array<string, int>  $counts
     */
    public static function of(array $counts): self
    {
        $counts = array_filter($counts, static fn (int $count): bool => $count > 0);
        ksort($counts);

        return new self($counts);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    /**
     * @return array{counts: array<string, int>, total: int}
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'total' => $this->total(),
        ];
    }
}
