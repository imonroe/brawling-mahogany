<?php

declare(strict_types=1);

namespace App\Support\Extraction\Redaction;

/**
 * F10.5 — strip financial and identity identifiers before anything leaves.
 *
 * PRD §9: *"No document reaches a third-party model without redaction."*
 * `RedactedDocument` is what makes that structural; this class is what makes
 * it true.
 *
 * ## The tension, stated rather than resolved
 *
 * PRD §4.10, and #114 repeats it so it cannot be softened in the UI or the
 * terms: *"Contracts contain exactly the personal financial information she is
 * worried about. F10.5 **narrows** the exposure. It does not eliminate it."*
 * Nothing in this file should be described as more than that. The one claim it
 * is entitled to make is that a routing number, an account number, a card
 * number, a social security number and a government ID number will not be in
 * the text that leaves — and that claim is bounded by the patterns below.
 *
 * ## The failure direction runs opposite to `SensitiveContent`
 *
 * The scanner's costly mistake is a false positive: refusing somebody's
 * disclosure teaches them the guardrail is noise, and CLAUDE.md records three
 * rounds of that pattern oscillating. Here the cost lands on **both** sides
 * and they are not the same cost:
 *
 * - Miss one, and a routing number is in a third party's logs.
 * - Take one too many, and #114's own line applies: *"Redaction cannot destroy
 *   the dates. A redactor that masks a purchase price or a deadline has broken
 *   the feature."*
 *
 * So the rule here is not "be aggressive" or "be precise". It is: **redact on
 * a label, never on a shape alone, and never over a date or an amount.** A
 * nine-digit run is a routing number, a parcel number, an MLS reference or a
 * phone number with the punctuation lost, and one in ten of them passes the
 * ABA checksum. What tells them apart is the words beside them, which is
 * exactly what a contract has and a bare digit column does not.
 *
 * The single exception is the card number, where Luhn over a 13-to-19 digit
 * run is strong enough on its own — and even there {@see self::isProtected()}
 * refuses to touch anything that reads as a date or a money amount first.
 *
 * ## What replaces it
 *
 * A named placeholder, not a run of `X`s. The model is being asked to read the
 * document, and `[redacted: account number]` tells it what it is looking at
 * without telling it what the number was — where `XXXXXXXXX` reads as a form
 * field somebody left blank and invites the model to hallucinate around it.
 */
final class Redactor
{
    /**
     * How far either side of a candidate to look for a label.
     *
     * Wide enough to cross `Account\nNumber:` and a table cell boundary,
     * narrow enough that the word "account" in the previous paragraph does not
     * claim a number two sentences later.
     */
    private const LABEL_WINDOW = 48;

    /**
     * Words that make a nearby number an identifier rather than a quantity.
     *
     * @var array<string, list<string>>
     */
    private const LABELS = [
        'routing_number' => ['routing', 'aba', 'rtn', 'transit number'],
        'account_number' => ['account number', 'account no', 'account #', 'acct no', 'acct #', 'account:', 'iban', 'swift'],
        'government_id' => [
            'social security', 'ssn', 'taxpayer identification', 'tin',
            'driver license', 'driver licence', "driver's license", "driver's licence",
            'passport', 'state id', 'identification number', 'alien registration',
        ],
    ];

    /** The E-13B symbols, as they transliterate when a MICR line becomes text. */
    private const MICR_SYMBOLS = ['⑆', '⑈', '⑇', '⑉'];

    /**
     * Read a document's words and hand back the version that may leave.
     *
     * The order below is not arbitrary. MICR first, because a MICR line is a
     * routing and an account number run together with no label between them
     * and every later rule would take only part of it. Then the labelled
     * rules, which are the ones that carry the argument. Then Luhn last, over
     * what is left, so a card number inside an already-masked MICR line is not
     * counted twice.
     */
    public function redact(string $text): RedactedDocument
    {
        $counts = [];

        $text = $this->maskMicrLines($text, $counts);
        $text = $this->maskSocialSecurityNumbers($text, $counts);

        foreach (self::LABELS as $rule => $labels) {
            $text = $this->maskLabelledNumbers($text, $rule, $labels, $counts);
        }

        $text = $this->maskCardNumbers($text, $counts);

        return RedactedDocument::of($text, RedactionReport::of($counts));
    }

    /**
     * A MICR line: the routing and account numbers off the bottom of a cheque.
     *
     * Conclusive on its own — the symbols exist nowhere else — so this needs
     * no label and takes the whole run between and around them rather than
     * trying to work out which digits are which.
     */
    private function maskMicrLines(string $text, array &$counts): string
    {
        $symbols = implode('', array_map(
            static fn (string $symbol): string => preg_quote($symbol, '/'),
            self::MICR_SYMBOLS,
        ));

        return $this->replace(
            '/[\d\s'.$symbols.']*['.$symbols.'][\d\s'.$symbols.']*/u',
            $text,
            'micr_line',
            $counts,
            static fn (string $match): bool => true,
        );
    }

    /**
     * A social security number.
     *
     * Two shapes, and the second is why this is not folded into the labelled
     * rules. `123-45-6789` is unambiguous on its own — no date, price or
     * parcel number in an American contract is punctuated that way. A bare
     * `123456789` is not, and only becomes one beside the words, which is what
     * the `government_id` label rule covers.
     */
    private function maskSocialSecurityNumbers(string $text, array &$counts): string
    {
        return $this->replace(
            '/\b\d{3}-\d{2}-\d{4}\b/u',
            $text,
            'social_security_number',
            $counts,
            static fn (string $match): bool => true,
        );
    }

    /**
     * A run of digits with one of these words beside it.
     *
     * The window is checked on the **original** text either side of the match,
     * so a label already replaced by an earlier rule cannot claim a second
     * number — and so the search is over words a human would recognise as a
     * caption rather than over whatever happens to be adjacent after three
     * substitutions.
     *
     * @param  list<string>  $labels
     */
    private function maskLabelledNumbers(string $text, string $rule, array $labels, array &$counts): string
    {
        return $this->replace(
            '/\b[\d][\d\- ]{5,24}\d\b|\b\d{6,17}\b/u',
            $text,
            $rule,
            $counts,
            fn (string $match, string $subject, int $offset): bool => ! $this->isProtected($match)
                && $this->hasLabelNear($subject, $offset, mb_strlen($match), $labels),
        );
    }

    /**
     * A payment card number: 13 to 19 digits that satisfy Luhn.
     *
     * The one rule here that fires without a label, because Luhn is a real
     * check rather than a shape: a random run of sixteen digits passes it one
     * time in ten, and a *contract* has very few sixteen-digit runs to begin
     * with. {@see self::isProtected()} still gets the veto, because the digits
     * of `$1,250,000.00` with the punctuation stripped are exactly the kind of
     * thing that would otherwise get through on a coincidence.
     */
    private function maskCardNumbers(string $text, array &$counts): string
    {
        return $this->replace(
            '/\b(?:\d[ -]?){12,18}\d\b/u',
            $text,
            'card_number',
            $counts,
            fn (string $match): bool => ! $this->isProtected($match) && $this->passesLuhn($match),
        );
    }

    /**
     * The veto: things that must survive redaction whatever else they look like.
     *
     * #114 again: *"A redactor that masks a purchase price or a deadline has
     * broken the feature."* This is where that is enforced, and it runs before
     * every rule that could fire on shape rather than on words.
     *
     * A candidate is protected when it reads as a date (`08/28/2026`,
     * `2026-08-28`), when it carries a decimal point or a currency mark, or
     * when it is short enough to be an ordinary quantity — a paragraph number,
     * a day count, a square footage. The `$` and `.` tests are deliberately
     * over the *matched run itself*: a price that reached here with its
     * punctuation intact is a price, and one that did not is indistinguishable
     * from a card number, which is why the amounts in a contract keep their
     * formatting through `ReadableText`.
     */
    private function isProtected(string $candidate): bool
    {
        $trimmed = trim($candidate);

        if (str_contains($trimmed, '.') || str_contains($trimmed, '$') || str_contains($trimmed, ',')) {
            return true;
        }

        // A date, in any of the shapes an American contract writes one.
        if (preg_match('#^\d{1,4}[-/]\d{1,2}[-/]\d{1,4}$#u', $trimmed) === 1) {
            return true;
        }

        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        // A bare year, a day count, a paragraph number, a square footage.
        if (mb_strlen($digits) <= 5) {
            return true;
        }

        // `08282026` and `20260828` — a date with its separators lost.
        return mb_strlen($digits) === 8 && $this->looksLikePackedDate($digits);
    }

    private function looksLikePackedDate(string $digits): bool
    {
        $year = (int) mb_substr($digits, 0, 4);

        if ($year >= 1900 && $year <= 2200) {
            $month = (int) mb_substr($digits, 4, 2);
            $day = (int) mb_substr($digits, 6, 2);

            return $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31;
        }

        $month = (int) mb_substr($digits, 0, 2);
        $day = (int) mb_substr($digits, 2, 2);
        $year = (int) mb_substr($digits, 4, 4);

        return $month >= 1 && $month <= 12
            && $day >= 1 && $day <= 31
            && $year >= 1900 && $year <= 2200;
    }

    /**
     * @param  list<string>  $labels
     */
    private function hasLabelNear(string $subject, int $offset, int $length, array $labels): bool
    {
        $start = max(0, $offset - self::LABEL_WINDOW);
        $before = mb_strtolower(mb_substr($subject, $start, $offset - $start));
        $after = mb_strtolower(mb_substr($subject, $offset + $length, self::LABEL_WINDOW));

        foreach ($labels as $label) {
            if (str_contains($before, $label) || str_contains($after, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Luhn, over the digits only.
     */
    private function passesLuhn(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';
        $length = mb_strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Run one rule, counting what it took.
     *
     * `preg_replace_callback` with `PREG_OFFSET_CAPTURE` so `$decide` can see
     * where in the *original* subject the candidate sat — which is what makes
     * the label window meaningful. Offsets are byte offsets, and the window is
     * measured in characters, so the subject is handed to `mb_substr` on the
     * byte offset: correct for the ASCII a digit run is surrounded by in
     * practice, and wrong only by a few characters of window in text that is
     * not, which is a margin this window has by design.
     */
    private function replace(string $pattern, string $subject, string $rule, array &$counts, callable $decide): string
    {
        $taken = 0;

        $result = preg_replace_callback(
            $pattern,
            function (array $matches) use ($subject, $rule, $decide, &$taken): string {
                [$match, $offset] = $matches[0];

                if (! $decide($match, $subject, $offset)) {
                    return $match;
                }

                $taken++;

                /*
                 * The surrounding whitespace is preserved rather than
                 * swallowed: a placeholder that eats the newline before it
                 * joins two table rows together, and the model then reads a
                 * date from the wrong line.
                 */
                $leading = $this->edgeWhitespace($match, true);
                $trailing = $this->edgeWhitespace($match, false);

                return $leading.'[redacted: '.str_replace('_', ' ', $rule).']'.$trailing;
            },
            $subject,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        if (! is_string($result)) {
            /*
             * A backtrack limit or a bad UTF-8 sequence. Failing open would
             * hand the provider the unredacted text, which is the one outcome
             * this class exists to prevent, so the caller is told rather than
             * quietly given the original.
             */
            throw RedactionFailed::onRule($rule);
        }

        $counts[$rule] = ($counts[$rule] ?? 0) + $taken;

        return $result;
    }

    private function edgeWhitespace(string $match, bool $leading): string
    {
        $pattern = $leading ? '/^\s+/u' : '/\s+$/u';

        return preg_match($pattern, $match, $found) === 1 ? $found[0] : '';
    }
}
