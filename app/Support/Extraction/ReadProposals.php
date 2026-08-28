<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ExtractedFieldType;
use App\Enums\ExtractionKind;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turn a provider's answer into proposals, or refuse it.
 *
 * ## Everything here is defensive, and none of it is paranoia
 *
 * This is the boundary between a system with rules and a system that produces
 * plausible text. A model asked for JSON returns JSON almost always — and the
 * cases where it does not are exactly the cases where a lenient reader writes
 * garbage into `extracted_fields` and a person on S66 confirms it because it
 * looked like everything else on the screen.
 *
 * So: unknown keys are dropped, a row missing its value is dropped, a
 * confidence outside 0..1 is clamped rather than trusted, a `field_type` the
 * extraction's kind cannot produce is dropped (`ExtractionKind::proposes()`),
 * and a response that yields nothing usable at all is a failure rather than an
 * empty success. That last one matters: an empty extraction and a broken one
 * look identical on a screen, and only one of them is worth a person's time to
 * retry.
 *
 * ## A date is not parsed here
 *
 * `proposed_value` holds the string the model produced. It is normalised to
 * `YYYY-MM-DD` when it parses as a date and left alone when it does not,
 * because S66's whole job is showing a human what the model actually said. A
 * reader that silently corrected `"Marhc 28"` into a date would be making the
 * judgement the review screen exists to take away from it.
 */
final class ReadProposals
{
    /** A snippet longer than this is truncated; it is a quote, not the document. */
    private const MAX_SNIPPET = 600;

    /** Beyond this many proposals, something has gone wrong rather than right. */
    private const MAX_PROPOSALS = 200;

    /**
     * @param  array<string, mixed>  $raw
     * @return list<Proposal>
     *
     * @throws ProviderFailed
     */
    public function from(array $raw, ExtractionKind $kind): array
    {
        $answer = $this->answerOf($raw);

        if ($answer === null) {
            throw ProviderFailed::unreadableResponse();
        }

        $allowed = $kind->proposes();

        $proposals = [
            ...$this->dates($answer, $allowed),
            ...$this->provisions($answer, $allowed),
            ...$this->tasks($answer, $allowed),
        ];

        if ($proposals === []) {
            /*
             * Deliberately a failure and not an empty list.
             *
             * A contract with no dates in it does not exist, and an inspection
             * report with nothing worth doing is a report the model was asked
             * about the wrong document. Either way the useful thing to show is
             * *"this did not work"* rather than an empty review screen that
             * reads as *"there was nothing in your contract"* — which somebody
             * would believe.
             */
            throw ProviderFailed::unreadableResponse();
        }

        return array_slice($proposals, 0, self::MAX_PROPOSALS);
    }

    /**
     * Find the JSON object inside the provider's envelope.
     *
     * The Messages API returns `content` as a list of blocks, and the text
     * block holds what the prompt asked for. Two failures are tolerated here
     * because they are common and harmless: a code fence the prompt asked the
     * model not to use, and leading prose before the object. Anything else is
     * not tolerated, because "find some JSON somewhere in this" is how a
     * reader ends up parsing an example out of an apology.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function answerOf(array $raw): ?array
    {
        $content = $raw['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text = is_string($block['text'] ?? null) ? $block['text'] : '';
            $decoded = $this->decode($text);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        $text = trim($text);

        // A ```json fence the prompt asked for and did not get.
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            $decoded = json_decode(
                substr($text, $start, $end - $start + 1),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<ExtractedFieldType>  $allowed
     * @return list<Proposal>
     */
    private function dates(array $answer, array $allowed): array
    {
        if (! in_array(ExtractedFieldType::KeyDate, $allowed, true)) {
            return [];
        }

        $proposals = [];

        foreach ($this->rows($answer, 'dates') as $row) {
            $label = $this->string($row, 'label');
            $value = $this->string($row, 'value');

            if ($label === null || $value === null) {
                continue;
            }

            $proposals[] = new Proposal(
                type: ExtractedFieldType::KeyDate,
                label: $label,
                value: $this->asDay($value),
                confidence: $this->confidence($row),
                sourcePage: $this->page($row),
                sourceSnippet: $this->snippet($row),
                payload: array_filter([
                    'critical' => (bool) ($row['critical'] ?? false),
                    'derivation' => $this->string($row, 'derivation'),
                    /*
                     * Kept even when `value` parsed cleanly, because S66 has to
                     * be able to show that a date was *worked out* rather than
                     * read — an offset the model resolved wrongly looks exactly
                     * like a date it read correctly, and only this tells them
                     * apart.
                     */
                    'raw_value' => $value,
                ], static fn (mixed $item): bool => $item !== null),
            );
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<ExtractedFieldType>  $allowed
     * @return list<Proposal>
     */
    private function provisions(array $answer, array $allowed): array
    {
        if (! in_array(ExtractedFieldType::Provision, $allowed, true)) {
            return [];
        }

        $proposals = [];

        foreach ($this->rows($answer, 'provisions') as $row) {
            $summary = $this->string($row, 'summary');

            if ($summary === null) {
                continue;
            }

            $proposals[] = new Proposal(
                type: ExtractedFieldType::Provision,
                /*
                 * A provision has no name of its own — it is a sentence. The
                 * label is the type's own word so the review card has a
                 * heading, and the sentence is the value.
                 */
                label: 'Provision',
                value: $summary,
                confidence: $this->confidence($row),
                sourcePage: $this->page($row),
                sourceSnippet: $this->snippet($row),
            );
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<ExtractedFieldType>  $allowed
     * @return list<Proposal>
     */
    private function tasks(array $answer, array $allowed): array
    {
        if (! in_array(ExtractedFieldType::Task, $allowed, true)) {
            return [];
        }

        $proposals = [];

        foreach ($this->rows($answer, 'tasks') as $row) {
            $title = $this->string($row, 'title');

            if ($title === null) {
                continue;
            }

            $severity = $this->string($row, 'severity');

            $proposals[] = new Proposal(
                type: ExtractedFieldType::Task,
                label: $title,
                value: $title,
                confidence: $this->confidence($row),
                sourcePage: $this->page($row),
                sourceSnippet: $this->snippet($row),
                payload: array_filter([
                    'detail' => $this->string($row, 'detail'),
                    'severity' => in_array($severity, ['safety', 'material', 'minor'], true)
                        ? $severity
                        : null,
                ], static fn (mixed $item): bool => $item !== null),
            );
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    private function rows(array $answer, string $key): array
    {
        $rows = $answer[$key] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function confidence(array $row): ?float
    {
        $value = $row['confidence'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        /*
         * Clamped, not refused. A confidence outside the range is a model
         * misreading the instruction about the *scale*, which says nothing
         * about whether it read the contract — and dropping the row over it
         * would trade a cosmetic problem for the one failure PRD §12.3 has
         * zero tolerance for.
         */
        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function page(array $row): ?int
    {
        $value = $row['page'] ?? null;

        if (! is_int($value) || $value < 1 || $value > 9999) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function snippet(array $row): ?string
    {
        $quote = $this->string($row, 'quote');

        if ($quote === null) {
            return null;
        }

        return mb_substr($quote, 0, self::MAX_SNIPPET);
    }

    /**
     * Normalise a date the model returned, or hand back exactly what it said.
     *
     * The prompt asks for `YYYY-MM-DD` and mostly gets it. `2026-3-4` and
     * `March 28, 2026` are the same date written differently and are worth
     * normalising, because S66 renders a date field and a value it cannot parse
     * renders as an empty one.
     *
     * Anything else is returned untouched. `Carbon::parse` is deliberately not
     * reached for here: it reads `"ten days after closing"` as *now*, which
     * would put today's date on the screen with the model's confidence beside
     * it and no sign that anything was guessed.
     */
    private function asDay(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        foreach (['Y-n-j', 'n/j/Y', 'm/d/Y', 'F j, Y', 'j F Y', 'M j, Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                /*
                 * Carbon 3 **throws** on a value that does not match, where
                 * Carbon 2 returned false. Both are handled, because the
                 * difference is a fatal in a queue worker on the ordinary
                 * input — a model that wrote "ten days after closing" — and
                 * the failure would surface as a stack trace where a proposal
                 * should be.
                 */
                continue;
            }

            /*
             * Round-tripped, not merely parsed. `createFromFormat` is lenient
             * about overflow: `2026-13-45` parses to a real day in 2027, which
             * would put a confident-looking date on the review screen that is
             * nowhere in the contract. Re-formatting and comparing is what
             * refuses it.
             */
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        return $value;
    }
}
