<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\RestrictedDocumentCategory;

/**
 * F6.7's heuristic scan (PRD §4.6, §8.4, §10, §14.1 Q6 · issue #100).
 *
 * ## The circularity, and what the design can actually promise
 *
 * PRD §4.6 states the objection and then answers it: to know a file is a
 * cheque, the system must already have received it. There is no way around
 * that, so the design minimises the window instead — scan on receipt, refuse
 * and discard before anything reaches permanent storage, log the refusal
 * without the file, and never hand a refused document to a third party.
 *
 * **One honest correction to the word "in memory".** PHP writes an upload to
 * its own temporary directory before a single line of this application runs;
 * `UploadedFile` is a handle to that file. So the accurate promise is not
 * *"the bytes never touch a disk"* — they already have — but *"the bytes never
 * reach the permanent store, and the temporary copy is unlinked when the
 * request ends"*. PRD §14.3 says not to let copy claim more than §8.4
 * delivers, and this is the sentence that would be the overclaim.
 *
 * ## Precision over recall, deliberately, and the reasoning is not the usual one
 *
 * Most scanners prefer a false positive to a false negative. This one is the
 * other way round, for two reasons that both come from the product:
 *
 * 1. **The miss is already covered.** F6.6's warning is a **Must** and appears
 *    at every upload point; F6.7 is only a **Should**. A missed cheque meets a
 *    warning the person read thirty seconds earlier. A false positive meets
 *    nothing — it simply refuses a legitimate inspection report, and the
 *    person cannot complete their work.
 * 2. **Q6's objection is about the implied guarantee**, not about the recall
 *    number: *"a scan that misses half the checks may be worse than no scan,
 *    because it implies a guarantee that is not there."* The answer to that is
 *    honest copy and an honest `not_scanned` state, not a looser pattern.
 *
 * So a lone weak signal never refuses. A **conclusive** signal refuses on its
 * own; two independent weak ones refuse together.
 *
 * ## What it cannot see at all
 *
 * Images. This application has no OCR, and PRD §14.3 names a photographed
 * cheque as the single largest liability in the product — so the thing the
 * scan is weakest against is the thing most likely to arrive. That is not a
 * flaw to be fixed by tuning; it is the reason the **warning** is the Must and
 * this is the Should, and it is why {@see ReadableText} reports unreadable
 * rather than clean. `tests/Unit/Documents/SensitiveContentTest.php` measures
 * the rate against a corpus and records it.
 */
final class SensitiveContent
{
    /**
     * Words that appear on a cheque and almost nowhere else.
     *
     * `pay to the order of` is the strongest string in the set: it is a legal
     * formula, not a phrase somebody writes by accident in a disclosure.
     */
    private const CHEQUE_PHRASES = [
        'pay to the order of',
        'payable to the order of',
        'void after 90 days',
        'void after ninety days',
    ];

    /** The E-13B symbols, as they transliterate when a MICR line becomes text. */
    private const MICR_SYMBOLS = ['⑆', '⑈', '⑇', '⑉'];

    private const STATEMENT_PHRASES = [
        'beginning balance',
        'ending balance',
        'statement period',
        'available balance',
        'deposits and additions',
        'withdrawals and subtractions',
    ];

    /**
     * Phrases that name a lending document without being one on their own.
     *
     * An agent explaining the Closing Disclosure timeline to a buyer writes
     * one of these; the packet itself carries several.
     */
    private const LENDING_PHRASES = [
        'loan estimate',
        'closing disclosure',
        'truth in lending',
        'annual percentage rate (apr)',
        'total interest percentage',
        'lender credits',
        'estimated escrow',
        'loan terms',
    ];

    /**
     * Titles that appear on the form and essentially nowhere else, so one is
     * enough. The test for this list is whether an agent would ever type the
     * phrase in a covering note.
     */
    private const LENDING_TITLES = [
        'uniform residential loan application',
        'form 1003',
    ];

    /**
     * A contract that has been **signed**, which is the part that matters.
     *
     * Round 1 of review: this category was named in S51's warning and in the
     * help article, `alternative()` had a branch for it, and nothing could
     * ever produce one. A refusal list with a category no detector reaches is
     * a promise the product does not keep.
     *
     * Signature evidence rather than contract vocabulary, and the distinction
     * is the whole rule. Every purchase agreement a team handles says
     * "purchase agreement"; PRD §1.1 puts this product *alongside* the
     * e-signature platform rather than in front of it, so the unexecuted draft
     * somebody is negotiating is exactly the document they should be able to
     * keep here. What belongs in CTM is the one that has been signed.
     */
    private const EXECUTION_PHRASES = [
        'docusign envelope id',
        'dotloop verified',
        'electronically signed by',
        'digitally signed by',
        'signature certificate',
        'certificate of completion',
        'audit trail',
        '/s/',
    ];

    private const CONTRACT_PHRASES = [
        'purchase agreement',
        'purchase and sale agreement',
        'residential sale contract',
        'in witness whereof',
        'buyer signature',
        'seller signature',
    ];

    private const IDENTITY_PHRASES = [
        'social security number',
        'driver license number',
        'driver\'s license number',
        'passport number',
        'date of birth',
    ];

    /**
     * Look at one upload and decide.
     *
     * The bytes are handed in rather than a path, because a caller that could
     * pass a path is a caller that could pass a stored one — and the whole
     * point is that this runs before anything is stored.
     */
    public static function scan(string $bytes, string $mimeType): ScanOutcome
    {
        $text = ReadableText::from($bytes, $mimeType);

        if ($text === null || trim($text) === '') {
            return ScanOutcome::unreadable();
        }

        /*
         * **However little there is.** Round 2 of review found the confidence
         * floor sitting in front of this, so the five shortest documents in
         * the threat model — a MICR-only cheque, a one-line wire instruction,
         * an SSN card — were never scanned at all. Confidence decides the
         * *label* at the bottom of this method; it never decides whether to
         * look.
         */
        $haystack = self::collapsed($text);
        $squashed = self::squashed($text);

        /*
         * Conclusive on its own. A MICR line is the machine-readable strip
         * along the bottom of a cheque and has no other reason to exist, and
         * "pay to the order of" is a legal formula rather than a phrase.
         */
        foreach (self::MICR_SYMBOLS as $symbol) {
            if (str_contains($squashed, $symbol)) {
                return ScanOutcome::refused(
                    RestrictedDocumentCategory::EarnestMoneyInstrument,
                    'micr_line',
                );
            }
        }

        foreach (self::CHEQUE_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase) || str_contains($squashed, self::squashed($phrase))) {
                return ScanOutcome::refused(
                    RestrictedDocumentCategory::EarnestMoneyInstrument,
                    'cheque_phrasing',
                );
            }
        }

        /*
         * A US Social Security number is nine digits in a shape nothing else
         * uses, and the area, group and serial parts each exclude zero — which
         * is what stops a date or a part number matching.
         *
         * **The separator is not always a hyphen**, and it is not always one
         * character. Round 1 found the pattern matching only `123-45-6789`;
         * round 3 found this branch to be the **one test not routed through
         * the normalisers**, so a column-aligned `123  45  6789` — which the
         * previous revision refused — came back `clean`. That is the worst
         * possible answer: a positive claim of "text read, nothing refused"
         * over a social security number.
         *
         * Matching `$haystack` rather than `$text` collapses the alignment
         * away and costs nothing: the single-space form still needs a digit
         * boundary either side, which is what keeps `1,204.55 2026` — a tax
         * proration line — from reading as an SSN.
         */
        if (preg_match('/\b(?!000|666|9\d\d)\d{3}[-\x{2010}-\x{2015}.]\s?(?!00)\d{2}[-\x{2010}-\x{2015}.]\s?(?!0000)\d{4}\b/u', $haystack) === 1
            || preg_match('/(?<!\d)(?!000|666|9\d\d)\d{3}\s(?!00)\d{2}\s(?!0000)\d{4}(?!\d)/u', $haystack) === 1) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::GovernmentId,
                'ssn_pattern',
            );
        }

        /*
         * A routing number that is **labelled as one**, not merely nine digits
         * that survive a checksum somewhere in the same document.
         *
         * The pairing rule this replaces was the false-positive engine round 3
         * measured: the ABA checksum passes 10% of nine-digit runs, so every
         * parcel number and MLS reference had a one-in-ten chance of arming
         * it, and `BANKING_CONTEXT` matched by substring — `aba` inside "tax
         * abatement" and "Alabama", `swift` inside "swiftly". A settlement
         * statement, an MLS printout and the **wire fraud advisory brokerages
         * are required to circulate** were all refused, with no override.
         *
         * Proximity is the honest signal. A real statement writes "Routing
         * Number: 021000021"; a parcel number is not introduced by the word
         * routing, and an advisory that warns about wire fraud carries no
         * nine-digit number at all.
         */
        if (self::hasLabelledRoutingNumber($haystack) || self::hasLabelledRoutingNumber($squashed)) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::BankStatement,
                'routing_number_in_banking_context',
            );
        }

        /*
         * Signature evidence **beside** contract vocabulary. Either alone is
         * wrong: "audit trail" appears in this product's own documentation,
         * and "purchase agreement" is what the draft somebody is negotiating
         * is called. Together they are a document that has been executed.
         */
        if (self::countOfEither($haystack, $squashed, self::EXECUTION_PHRASES) > 0
            && self::countOfEither($haystack, $squashed, self::CONTRACT_PHRASES) > 0) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::ExecutedContract,
                'execution_evidence_in_contract',
            );
        }

        if (self::countOfEither($haystack, $squashed, self::STATEMENT_PHRASES) >= 2) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::BankStatement,
                'statement_phrasing',
            );
        }

        /*
         * **Two, not one**, and round 1 of review is why: on a single phrase
         * this refused an agent's own email explaining the Closing Disclosure
         * timeline — a sentence every buyer's agent writes — as a lending
         * packet. One phrase is a *mention*; the document itself repeats its
         * vocabulary, and the class's stated rule everywhere else is that a
         * single weak signal does not refuse.
         *
         * The exception is a phrase that is only ever a form's own title.
         * Nobody writes "uniform residential loan application" in a covering
         * note, so those refuse alone.
         */
        if (self::countOfEither($haystack, $squashed, self::LENDING_TITLES) > 0
            || self::countOfEither($haystack, $squashed, self::LENDING_PHRASES) >= 2) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::LendingPacket,
                'lending_phrasing',
            );
        }

        /*
         * Two identity phrases, not one. A disclosure legitimately asks for a
         * date of birth; a form carrying a date of birth *and* a licence
         * number is an identity document.
         */
        if (self::countOfEither($haystack, $squashed, self::IDENTITY_PHRASES) >= 2) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::GovernmentId,
                'identity_phrasing',
            );
        }

        /*
         * **Nothing was found — but was there anything to find?**
         *
         * This is the only place confidence is consulted, and it is the last
         * question rather than the first. A decode that produced a few bytes,
         * or a page of armoured binary that happened to yield letters, has not
         * been *checked* in any sense a person would recognise, and `clean` is
         * a word this product must only use when it means something.
         *
         * `not_scanned` claims nothing, which is the right answer when nothing
         * can honestly be claimed. Every screen distinguishes the two.
         */
        return ReadableText::isConfident($text)
            ? ScanOutcome::clean()
            : ScanOutcome::unreadable();
    }

    /**
     * A nine-digit run that satisfies the ABA checksum.
     *
     * The checksum is what makes this worth testing at all: without it, any
     * nine consecutive digits would match, and a parcel number or an MLS
     * reference would refuse an inspection report. With it, roughly one run in
     * ten survives — still far too weak alone, which is why the caller pairs
     * it with the banking words.
     */
    private static function hasLabelledRoutingNumber(string $haystack): bool
    {
        /*
         * The label has to be **near** the number, not merely present. A
         * settlement statement that mentions routing instructions in one
         * paragraph and carries a parcel number in another is not a bank
         * statement, and treating the document as one window is how it became
         * one.
         *
         * The window is generous — a label, a colon, some alignment and the
         * digits — and deliberately looks **backwards** only: "021000021
         * routing" is a sentence about a number somebody already has, while
         * "Routing 021000021" is a field.
         */
        if (preg_match_all('/\b(?:routing(?:\s*(?:number|no\.?|#))?|aba(?:\s*(?:number|no\.?|#))?|rtn)\b(.{0,40}?)(\d{9})(?!\d)/us', $haystack, $matches, PREG_SET_ORDER) === false) {
            return false;
        }

        foreach ($matches as $match) {
            if (self::passesAbaChecksum($match[2])) {
                return true;
            }
        }

        /*
         * The squashed form has no spaces to put a window in, so it asks the
         * narrower question: the label immediately against the digits, which
         * is what a kerning split leaves behind.
         */
        if (preg_match_all('/(?:routingnumber|routingno|abanumber|aba|rtn)(\d{9})(?!\d)/u', $haystack, $tight, PREG_SET_ORDER) === false) {
            return false;
        }

        foreach ($tight as $match) {
            if (self::passesAbaChecksum($match[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse every whitespace run to one space, and lower-case it.
     *
     * PDF text extraction inserts separators the author never typed: a
     * justified line arrives with runs of spaces between words, and a table
     * cell arrives column-aligned. Round 2 of review measured the cost — an
     * identical bank statement was refused single-spaced and passed as
     * `clean` column-aligned. A phrase list matched against raw extraction is
     * a phrase list matched against one producer's spacing.
     */
    private static function collapsed(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * The same text with **every** space removed.
     *
     * Collapsing fixes spacing *between* words and cannot fix a space injected
     * *inside* one: a kerning pair splits `PAY` into `[(PA) -20 (Y …)]`, and
     * the extractor rebuilds it as `PA Y TO THE ORDER OF` — a cheque that
     * walks past every phrase in the list. Matching a squashed needle against
     * squashed text is blind to where the producer chose to break, which is
     * the only property that survives every producer.
     *
     * It costs the ability to require a word boundary, so it is used *beside*
     * the collapsed form rather than instead of it.
     */
    private static function squashed(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $text));
    }

    /**
     * The ABA checksum, on one nine-digit run.
     *
     * `3(d1+d4+d7) + 7(d2+d5+d8) + (d3+d6+d9) ≡ 0 mod 10`.
     *
     * It passes roughly one run in ten, which is why it is never the whole
     * question: the caller has already established that a **label** points at
     * these particular digits, and this is what separates a routing number
     * from nine digits somebody typed after the word routing.
     */
    private static function passesAbaChecksum(string $digits): bool
    {
        $sum = 0;

        foreach ([3, 7, 1, 3, 7, 1, 3, 7, 1] as $index => $weight) {
            $sum += $weight * (int) $digits[$index];
        }

        return $sum > 0 && $sum % 10 === 0;
    }

    /**
     * How many of these phrases appear, in **either** normalised form.
     *
     * One helper rather than a collapsed check and a squashed check at each
     * call site: round 2 of review found the whitespace hole because the rule
     * lived in the callers, and a rule in seven callers is a rule the eighth
     * is written without. Every phrase test in this class goes through here.
     *
     * @param  list<string>  $needles
     */
    private static function countOfEither(string $haystack, string $squashed, array $needles): int
    {
        $found = 0;

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle) || str_contains($squashed, self::squashed($needle))) {
                $found++;
            }
        }

        return $found;
    }
}
