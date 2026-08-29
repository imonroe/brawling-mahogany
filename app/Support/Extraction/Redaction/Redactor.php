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
     *
     * Measured against the corpus (#14), in both directions, and the number is
     * a compromise the corpus itself settled.
     *
     * Narrowing it to 32 fixed an *Account Number* caption that was claiming an
     * escrow file reference two lines down — and broke a wire instruction where
     * the caption is wrapped: `Routing\n    Number  . . . . .  987654321` puts
     * the word "Routing" 34 characters back, so a 32-character window began
     * mid-word and matched nothing. **A guardrail that fails the wire
     * instruction is the guardrail failing the one document it was written
     * about.**
     *
     * So the window stays wide and the *paragraph break* does the narrowing
     * instead — see {@see self::hasLabelNear()}. That is the rule that actually
     * describes what a caption reaches: the block it is in.
     */
    private const LABEL_WINDOW = 48;

    /**
     * Words that make a nearby number an identifier rather than a quantity.
     *
     * **Matched on word boundaries, not as substrings**, and the corpus is why
     * (#14). `tin` — for taxpayer identification number — is inside `lighting`,
     * `listing` and `existing`, and every Colorado contract has an inclusions
     * clause. A schedule number beside the word `lighting` was being masked as
     * a government ID, which is exactly the *"damage to extractable content"*
     * #114 asks the corpus to measure.
     *
     * @var array<string, list<string>>
     */
    private const LABELS = [
        'routing_number' => ['routing', 'aba', 'rtn', 'transit number'],
        'account_number' => ['account number', 'account no', 'account #', 'acct no', 'acct #', 'account:', 'iban', 'swift'],
        'government_id' => [
            'social security', 'ssn', 'taxpayer identification', 'tin',
            'driver license', 'driver licence', 'drivers license', 'drivers licence',
            "driver's license", "driver's licence",
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
     *
     * @param  array<string, int>  $counts
     */
    private function maskMicrLines(string $text, array &$counts): string
    {
        $symbols = implode('', array_map(
            static fn (string $symbol): string => preg_quote($symbol, '/'),
            self::MICR_SYMBOLS,
        ));

        /*
         * Anchored on the symbols, and the run either side may not begin or
         * end on a bare digit reached across a line break.
         *
         * The first version was `[\d\s⑆⑈⑇⑉]*` on both sides, which starts at
         * the beginning of *any* run of digits or whitespace before the first
         * symbol — so a line ending in digits directly above a MICR line lost
         * those digits into the placeholder, and `edgeWhitespace()` restores
         * only whitespace. Found in review against the corpus. Keeping the run
         * on one line is what makes "the whole run between and around them"
         * true rather than approximately true.
         */
        return $this->replace(
            '/[\d ]*['.$symbols.'][\d '.$symbols.']*/u',
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
     *
     * @param  array<string, int>  $counts
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
     * The window is searched for words a human would recognise as a caption
     * rather than for whatever happens to be adjacent after three
     * substitutions — which is a property {@see self::hasLabelNear()} has to
     * *enforce* rather than one this class gets for free. Each rule runs over
     * the previous rule's output, so the text here is the original document
     * only for the first of the six.
     *
     * @param  list<string>  $labels
     * @param  array<string, int>  $counts
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
     * A payment card number: a card-shaped run of digits that satisfies Luhn.
     *
     * The one rule here that fires without a label, because Luhn is a real
     * check rather than a shape.
     *
     * ## What the corpus corrected (#14)
     *
     * The first version took any 13-to-19 digit run that passed Luhn, and a
     * Colorado county **schedule number** — `6318-04-031-0310` — is thirteen
     * digits and passes it. That defeated this class's own argument: the
     * docblock above says words are what tell a parcel number from a card, and
     * then this rule ignored words entirely on a shape a parcel number has.
     *
     * So the shape is narrowed to the ones cards actually take: 15, 16 or 19
     * digits, either contiguous or grouped in fours. A schedule number grouped
     * 4-2-3-4 no longer matches, and neither does a thirteen-digit anything.
     * The residual risk is a genuine 13- or 14-digit card written without
     * grouping, which is a card format effectively out of use — and one the
     * labelled `account_number` rule still catches when it is captioned.
     *
     * @param  array<string, int>  $counts
     */
    private function maskCardNumbers(string $text, array &$counts): string
    {
        return $this->replace(
            '/\b(?:\d{4}[ -]){3}\d{3,4}\b|\b\d{15,16}\b|\b\d{19}\b/u',
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
     * Is one of these captions close enough to claim this number?
     *
     * Two narrowings the corpus argued for, both of them cases where a caption
     * reached something it had no business reaching:
     *
     * - **The window stops at a blank line.** A caption belongs to the block it
     *   is in, so it may not reach across a paragraph break.
     *
     *   This does **not** fix every case of a caption over-reaching, and the
     *   docblock said it did for one round — corpus case `0009` puts an
     *   *Account Number* caption and an escrow file reference on consecutive
     *   lines *inside one block*, and the reference is still masked. It fails
     *   closed: a field arrives at the model deleted rather than a number
     *   leaving. Narrowing further costs the wrapped `Routing\n  Number` case
     *   below, which is the wire instruction this whole rule exists for, so
     *   this is the trade as it stands rather than a problem left unnoticed.
     * - **The match is on word boundaries.** See {@see self::LABELS}.
     *
     * @param  list<string>  $labels
     */
    private function hasLabelNear(string $subject, int $offset, int $length, array $labels): bool
    {
        /*
         * **Neutralised before the window is cut, not after.**
         *
         * The strip used to run on the already-sliced window, and its pattern
         * needs *both* brackets — so a
         * `[redacted: social security number]` cut by the 48-character
         * boundary left a fragment that still said `social security` and no
         * longer said `[redacted:`. Nothing was stripped and the fragment
         * acted as exactly the caption the strip exists to remove.
         *
         * The bands are narrow, which is why one worked example missed them:
         * 6–16 characters of gap in the `before` direction and 13–20 in the
         * `after` one, with the fixture written for the fix sitting at 8 — the
         * one place the old order still reached. `Ref 123-45-6789. See sched.
         * Price 1250000 at closing.` destroys the price; delete the SSN and the
         * same price survives.
         *
         * Replacing with **spaces of equal length** is what makes the order
         * safe rather than merely earlier: `$offset` is a character offset into
         * this string, so anything that changed its length would move the
         * window off the candidate.
         *
         * `[^\]\n]*` rather than `[^\]]*`, because this now runs over the
         * whole subject: a stray `[redacted:` typed *in a document* must not
         * blank a span reaching to some unrelated `]` pages later.
         */
        $subject = (string) preg_replace_callback(
            '/\[redacted:[^\]\n]*\]/u',
            static fn (array $match): string => str_repeat(' ', mb_strlen($match[0])),
            $subject,
        );

        $start = max(0, $offset - self::LABEL_WINDOW);
        $before = mb_strtolower(mb_substr($subject, $start, $offset - $start));
        $after = mb_strtolower(mb_substr($subject, $offset + $length, self::LABEL_WINDOW));

        /*
         * **A placeholder this class wrote is not a caption the document
         * wrote**, and until it was neutralised it could act as one.
         *
         * `redact()` runs six rules in sequence, each over the *output* of the
         * last, so `$subject` is the original document only for the first.
         * `[redacted: social security number]` contains `social security`,
         * which is a `government_id` label — so an unlabelled SSN manufactured
         * a caption where the document had none, and the next digit run in the
         * window was masked as a government id. Reproduced on
         * `"Ref 123-45-6789.\nPrice 1250000 at closing."`, where the **purchase
         * price** is destroyed; delete the SSN and the same price survives.
         *
         * That is #114's other failure direction, the one this class's own
         * docblock calls out — *"a redactor that masks a purchase price or a
         * deadline has broken the feature"* — caused by the redactor's own
         * output. It is one pair today only because of the order the rules
         * happen to run in, which is not a property worth depending on, so the
         * whole class of contamination is removed rather than the one instance.
         */

        // Keep only the side of a paragraph break the candidate is on.
        $before = (string) preg_replace('/^.*\R\s*\R/su', '', $before);
        $after = (string) preg_replace('/\R\s*\R.*$/su', '', $after);

        foreach ($labels as $label) {
            if ($this->contains($before, $label) || $this->contains($after, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A whole-word match, tolerant of a label that ends in punctuation.
     *
     * `\b` after `account #` would never fire, because `#` is not a word
     * character and there is no boundary between it and a space. So the
     * trailing boundary is only asserted when the label ends in one.
     *
     * ## The separator is whitespace, not a space
     *
     * `preg_quote` does not escape a space, so `'account number'` compiled to a
     * pattern containing a literal U+0020 — and **ten of the seventeen labels
     * are multi-word**. Every one of them was defeated by whitespace a document
     * reader routinely produces: a wrapped caption (`Account\nNumber`), a
     * right-aligned column (`Account   Number`), a DOCX table cell boundary
     * (`Account\tNumber`), a Word non-breaking space (U+00A0, which nothing
     * between `ReadableText` and here normalises).
     *
     * The failure was sharpest on the document this rule exists for. In one
     * wire block the **routing number was masked and the account number beside
     * it was not**, because `routing` happens to be a single word — and
     * {@see self::LABEL_WINDOW} argues at length for staying wide enough to
     * cross exactly the wrapped caption the matcher then could not read.
     *
     * `\p{Zs}` is in there beside `\s` for the non-breaking space, which `\s`
     * does not cover under `/u`. The paragraph-break narrowing in
     * {@see self::hasLabelNear()} already stops a run of whitespace reaching
     * across a blank line, so this widens the separator without widening the
     * block a caption may claim.
     */
    private function contains(string $haystack, string $label): bool
    {
        /*
         * **The apostrophe is folded, and an abbreviation's stop is part of
         * the separator.** Whitespace was only one of three things a page puts
         * inside and between the words of a caption.
         *
         * `driver's license` is in `LABELS` with a straight quote, and Word
         * autocorrects one into U+2019 as it is typed — the very character
         * {@see self::replace()}'s docblock names as what these documents are
         * full of. Nothing between `ReadableText` and here normalises it, so
         * every licence label was defeated by a character the file already
         * warns about. There are **zero** U+2019 characters in all twenty
         * corpus fixtures, and `government_id` fires in none of them, so the
         * whole family had never been exercised outside a unit test written
         * with a straight quote.
         *
         * `acct no` is in the list *because* somebody expected the abbreviated
         * caption — and an abbreviation is written `Acct. No.`, which a
         * whitespace-only separator refuses. Corpus fixture `0017` happens to
         * write `Acct No . . . .` with no stop, which is the one variant that
         * worked.
         *
         * `[.,]?` is deliberately narrow: one optional stop or comma directly
         * after a word, not a general punctuation run, so the separator cannot
         * start swallowing sentence boundaries.
         */
        $haystack = str_replace(["\u{2019}", "\u{02BC}"], "'", $haystack);
        $label = str_replace(["\u{2019}", "\u{02BC}"], "'", $label);

        $pattern = '/\b'.str_replace(' ', '[.,]?[\s\p{Zs}]+', preg_quote($label, '/'));

        if (preg_match('/[a-z0-9]$/', $label) === 1) {
            $pattern .= '\b';
        }

        return preg_match($pattern.'/u', $haystack) === 1;
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
     * `$decide` is given where in the *original* subject the candidate sat,
     * which is what makes the label window meaningful — and it is given a
     * **character** offset, because {@see self::hasLabelNear()} slices that
     * window with `mb_substr`.
     *
     * ## The units are the whole rule, and this shipped wrong
     *
     * `preg_match_all` returns **byte** offsets. Passing one to a character
     * API is not an approximation, and the docblock here argued for a round
     * that it was — *"wrong only by a few characters of window in text that is
     * not [ASCII], which is a margin this window has by design."*
     *
     * It is neither a few characters nor bounded by the window. The error is
     * the **cumulative** count of extra bytes in every multi-byte character
     * earlier in the document, so it grows down the page without limit — and a
     * contract lifted out of a PDF or a DOCX is full of `’`, `—` and `§`,
     * three bytes each. Twenty-one excess bytes is enough: the window slides
     * far enough right that the paragraph-break narrowing keeps the wrong side
     * of the break, `$before` ends up holding the text *after* the number, and
     * a captioned account number is sent to the provider intact. Measured, on
     * a nine-line fixture whose byte-identical ASCII twin redacts correctly.
     *
     * That is #114's expensive direction and it is round 2's B1 one layer down
     * — the same sentence, *the label window is measured around the wrong
     * place, so the number is not redacted*. Two different mechanisms reached
     * it, which is why the conversion now happens once, here, rather than
     * being reasoned about at each call site.
     *
     * @param  array<string, int>  $counts
     */
    private function replace(string $pattern, string $subject, string $rule, array &$counts, callable $decide): string
    {
        /*
         * ## The offset has to be the **match's** offset, and nothing else will do
         *
         * This method has now been written three ways and only two of them are
         * correct. Recording why, because the wrong one looked fine and shipped:
         *
         * A `strpos($subject, $match, $cursor)` cursor was tried to get around
         * PHPStan's stub for `preg_replace_callback`, which does not model the
         * flags argument. It finds the first occurrence of the matched *text*,
         * which is not the same thing as where the regex matched — the same
         * digits can appear earlier inside a longer run the pattern could not
         * match there (glued to letters, so `\b` fails). The label window then
         * looks in the wrong place, finds no caption, and the number **is not
         * redacted**. That fails *open*, which is the one direction this class
         * must never fail in, and no test in the tree could see it because
         * every corpus identifier happens to be unique.
         *
         * So the offset comes from `PREG_OFFSET_CAPTURE` and the string is
         * rebuilt by hand. `preg_match_all` gives the same information without
         * a callback, which means no closure for the analyser to mis-type, and
         * the splice below is plain `substr` arithmetic. A guardrail is not
         * worth trading for a green analyser; if the types are still awkward
         * the annotation is the thing to argue with, never the behaviour.
         */
        $found = preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);

        if ($found === false) {
            /*
             * A backtrack limit or a bad UTF-8 sequence. Failing open would
             * hand the provider the unredacted text, which is the one outcome
             * this class exists to prevent, so the caller is told rather than
             * quietly given the original.
             */
            throw RedactionFailed::onRule($rule);
        }

        $counts[$rule] ??= 0;

        if ($found === 0) {
            return $subject;
        }

        $result = '';
        $consumed = 0;
        $consumedChars = 0;

        foreach ($matches[0] as $capture) {
            $match = (string) $capture[0];
            $offset = (int) $capture[1];

            // Everything between the previous match and this one, untouched.
            $between = substr($subject, $consumed, $offset - $consumed);

            /*
             * The byte offset converted to a character offset, counted along
             * the walk rather than by re-measuring the prefix each time: the
             * loop already holds every byte between the last match and this
             * one, so the conversion is O(n) over the document rather than
             * O(n·m). Exact, not approximate — see the docblock.
             */
            $charOffset = $consumedChars + mb_strlen($between);

            $result .= $between;
            $consumed = $offset + strlen($match);
            $consumedChars = $charOffset + mb_strlen($match);

            if (! $decide($match, $subject, $charOffset)) {
                $result .= $match;

                continue;
            }

            $counts[$rule]++;

            /*
             * The surrounding whitespace is preserved rather than swallowed: a
             * placeholder that eats the newline before it joins two table rows
             * together, and the model then reads a date from the wrong line.
             */
            $result .= $this->edgeWhitespace($match, true)
                .'[redacted: '.str_replace('_', ' ', $rule).']'
                .$this->edgeWhitespace($match, false);
        }

        return $result.substr($subject, $consumed);
    }

    private function edgeWhitespace(string $match, bool $leading): string
    {
        $pattern = $leading ? '/^\s+/u' : '/\s+$/u';

        return preg_match($pattern, $match, $found) === 1 ? $found[0] : '';
    }
}
