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

    private const LENDING_PHRASES = [
        'loan estimate',
        'closing disclosure',
        'uniform residential loan application',
        'form 1003',
        'truth in lending',
        'annual percentage rate (apr)',
    ];

    private const IDENTITY_PHRASES = [
        'social security number',
        'driver license number',
        'driver\'s license number',
        'passport number',
        'date of birth',
    ];

    /** Words that make a nearby nine-digit run mean a bank and not an invoice. */
    private const BANKING_CONTEXT = [
        'routing',
        'aba',
        'account number',
        'acct no',
        'account no',
        'swift',
        'wire transfer',
        'direct deposit',
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

        if ($text === null) {
            return ScanOutcome::unreadable();
        }

        $haystack = mb_strtolower($text);

        /*
         * Conclusive on its own. A MICR line is the machine-readable strip
         * along the bottom of a cheque and has no other reason to exist, and
         * "pay to the order of" is a legal formula rather than a phrase.
         */
        foreach (self::MICR_SYMBOLS as $symbol) {
            if (str_contains($text, $symbol)) {
                return ScanOutcome::refused(
                    RestrictedDocumentCategory::EarnestMoneyInstrument,
                    'micr_line',
                );
            }
        }

        foreach (self::CHEQUE_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
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
         */
        if (preg_match('/\b(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/', $text) === 1) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::GovernmentId,
                'ssn_pattern',
            );
        }

        /*
         * A routing number **beside banking words**. Either alone is weak: a
         * nine-digit invoice number passes the checksum one time in ten, and
         * the word "account" appears in half the correspondence a team writes.
         * Together they are a bank document.
         */
        if (self::hasRoutingNumber($text) && self::mentions($haystack, self::BANKING_CONTEXT)) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::BankStatement,
                'routing_number_in_banking_context',
            );
        }

        if (self::countOf($haystack, self::STATEMENT_PHRASES) >= 2) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::BankStatement,
                'statement_phrasing',
            );
        }

        if (self::mentions($haystack, self::LENDING_PHRASES)) {
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
        if (self::countOf($haystack, self::IDENTITY_PHRASES) >= 2) {
            return ScanOutcome::refused(
                RestrictedDocumentCategory::GovernmentId,
                'identity_phrasing',
            );
        }

        return ScanOutcome::clean();
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
    private static function hasRoutingNumber(string $text): bool
    {
        if (preg_match_all('/\b\d{9}\b/', $text, $matches) === false) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $d = array_map(intval(...), mb_str_split($candidate));

            $sum = 3 * ($d[0] + $d[3] + $d[6])
                + 7 * ($d[1] + $d[4] + $d[7])
                + ($d[2] + $d[5] + $d[8]);

            /*
             * All-zeroes passes the arithmetic and is not a routing number —
             * it is a redacted field, a placeholder, or a run of padding.
             */
            if ($sum % 10 === 0 && $sum > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function mentions(string $haystack, array $needles): bool
    {
        return self::countOf($haystack, $needles) > 0;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function countOf(string $haystack, array $needles): int
    {
        $found = 0;

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $found++;
            }
        }

        return $found;
    }
}
