<?php

declare(strict_types=1);

use App\Support\Documents\ReadableText;
use App\Support\Documents\SensitiveContent;

/**
 * F6.7's scan, and the measurement PRD §14.1 Q6 asks for (issue #100).
 *
 * Q6: *"Does the sensitive-content scan work well enough to be worth having?
 * A scan that misses half the checks may be worse than no scan, because it
 * implies a guarantee that is not there."*
 *
 * The corpus below is the answer, and it is deliberately in the test rather
 * than in a fixtures directory: a corpus somebody has to go and find is a
 * corpus that stops being run. Each case says what it is and what it should
 * do, and the two summary cases at the bottom turn the whole thing into the
 * two numbers #100 asks to be recorded.
 */

/**
 * @return list<array{name: string, text: string, refuse: bool}>
 */
function corpus(): array
{
    return [
        // ---- Should refuse -------------------------------------------------
        [
            'name' => 'a cheque with a MICR line',
            'text' => "PAY TO THE ORDER OF  Bosart Group   \$5,000.00\n⑆123456789⑆ 0001234567⑈ 1042",
            'refuse' => true,
        ],
        [
            'name' => 'a cheque image whose OCR layer kept the wording only',
            'text' => "First National Bank\nPay to the order of Bosart Group\nFive thousand and 00/100 Dollars\nMEMO earnest money",
            'refuse' => true,
        ],
        [
            'name' => 'a bank statement',
            'text' => "MONTHLY STATEMENT\nStatement period 1 July to 31 July\nBeginning balance \$4,201.55\nEnding balance \$3,880.12",
            'refuse' => true,
        ],
        [
            'name' => 'a wire instruction carrying a routing number',
            'text' => "Wire transfer instructions\nRouting: 021000021\nAccount number: 4409912",
            'refuse' => true,
        ],
        [
            'name' => 'a closing disclosure',
            'text' => "Closing Disclosure\nThis form is a statement of final loan terms and closing costs.",
            'refuse' => true,
        ],
        [
            'name' => 'a loan application',
            'text' => "Uniform Residential Loan Application\nBorrower information section 1a",
            'refuse' => true,
        ],
        [
            'name' => 'a form carrying a social security number',
            'text' => "Borrower: Dana Okafor\nSSN 123-45-6789\nSubject property 12 Oak Lane",
            'refuse' => true,
        ],
        [
            'name' => 'a photocopied licence',
            'text' => "COLORADO\nDriver license number 98-123-4567\nDate of birth 04/11/1981",
            'refuse' => true,
        ],

        // ---- Should not refuse ---------------------------------------------
        [
            'name' => 'an inspection report',
            'text' => "PROPERTY INSPECTION REPORT\n12 Oak Lane, Golden CO 80401\nRoof: asphalt shingle, approx 8 years, no visible damage.\nElectrical panel 200 amp. Report number 481516234.",
            'refuse' => false,
        ],
        [
            'name' => 'a seller property disclosure',
            'text' => "SELLER'S PROPERTY DISCLOSURE\nHas the roof leaked during your ownership? No.\nDate of birth of the structure: 1974.",
            'refuse' => false,
        ],
        [
            'name' => 'marketing copy with a phone number and a price',
            'text' => 'Just listed! 12 Oak Lane, offered at $675,000. Call 303-555-0142 to arrange a showing. MLS 8891234.',
            'refuse' => false,
        ],
        [
            'name' => 'a contractor receipt with a long invoice number',
            'text' => "INVOICE 021000021\nGutter replacement, labour and materials\nTotal due \$1,480.00\nThank you for your business.",
            'refuse' => false,
        ],
        [
            'name' => 'correspondence that happens to say account',
            'text' => "Hi Dana — I've set up your account on the status page so you can follow along. Nothing needed from you.",
            'refuse' => false,
        ],
        [
            'name' => 'an HOA letter quoting a parcel number',
            'text' => 'Parcel 123456789 is in good standing with the association as of this date. No outstanding assessments.',
            'refuse' => false,
        ],
        [
            'name' => 'a repair estimate mentioning a balance due',
            'text' => "Estimate for foundation work.\nDeposit \$2,000. Balance due on completion.",
            'refuse' => false,
        ],
    ];
}

it('refuses every restricted document in the corpus', function (): void {
    $missed = [];

    foreach (corpus() as $case) {
        if (! $case['refuse']) {
            continue;
        }

        if (! SensitiveContent::scan($case['text'], 'text/plain')->isRefused()) {
            $missed[] = $case['name'];
        }
    }

    expect($missed)->toBe([]);
});

it('refuses nothing in the corpus that a team legitimately uploads', function (): void {
    /*
     * The direction that matters more, and the reasoning is not the usual one:
     * F6.6's warning is a **Must** and covers a miss, so a false negative meets
     * a warning the person read thirty seconds earlier. A false positive meets
     * nothing — it refuses an inspection report and the work cannot proceed.
     */
    $wrongly = [];

    foreach (corpus() as $case) {
        if ($case['refuse']) {
            continue;
        }

        $outcome = SensitiveContent::scan($case['text'], 'text/plain');

        if ($outcome->isRefused()) {
            $wrongly[] = $case['name'].' → '.$outcome->signal;
        }
    }

    expect($wrongly)->toBe([]);
});

it('records the measured rates PRD §14.1 Q6 asks for', function (): void {
    /*
     * The number, in the file, rather than in a commit message. Q6 makes the
     * measurement the deciding factor for whether F6.7 ships at all and for
     * how #99's copy describes it, so it has to be re-derived every run rather
     * than quoted from the day somebody last looked.
     */
    $shouldRefuse = array_values(array_filter(corpus(), fn (array $c): bool => $c['refuse']));
    $shouldPass = array_values(array_filter(corpus(), fn (array $c): bool => ! $c['refuse']));

    $caught = count(array_filter(
        $shouldRefuse,
        fn (array $c): bool => SensitiveContent::scan($c['text'], 'text/plain')->isRefused(),
    ));

    $falsePositives = count(array_filter(
        $shouldPass,
        fn (array $c): bool => SensitiveContent::scan($c['text'], 'text/plain')->isRefused(),
    ));

    expect($caught)->toBe(count($shouldRefuse))
        ->and($falsePositives)->toBe(0);
});

it('reports an image as not scanned rather than as clean', function (): void {
    /*
     * The most important case in the file, and the one Q6 is really about.
     * PRD §14.3 names a photographed cheque as the single largest liability in
     * the product, and this application has no OCR — so the scan's answer for
     * the likeliest arrival is *"I could not look"*, and it must never be
     * recorded as a pass.
     */
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );

    $outcome = SensitiveContent::scan($png, 'image/png');

    expect($outcome->isRefused())->toBeFalse()
        ->and($outcome->readable)->toBeFalse()
        ->and($outcome->state())->toBe('not_scanned');
});

it('reports a PDF with no text layer as not scanned', function (): void {
    // A scanned photograph in a PDF wrapper — no text operators to find.
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

    expect(SensitiveContent::scan($pdf, 'application/pdf')->readable)->toBeFalse();
});

it('reads the text layer of a PDF that has one', function (): void {
    $pdf = "%PDF-1.4\n4 0 obj\n<< /Length 60 >>\nstream\nBT /F1 12 Tf (Pay to the order of Bosart Group) Tj ET\nendstream\nendobj\n%%EOF";

    expect(ReadableText::from($pdf, 'application/pdf'))->toContain('Pay to the order of')
        ->and(SensitiveContent::scan($pdf, 'application/pdf')->isRefused())->toBeTrue();
});

it('never names the content in what it reports', function (): void {
    /*
     * #100 item 3, and PRD §9: the log records that a refusal happened and its
     * category, never the content and never a copy. `signal` is the **kind**
     * of thing found — a scanner that returned the matched string would put a
     * routing number in a log line.
     */
    $outcome = SensitiveContent::scan(
        "Wire transfer instructions\nRouting: 021000021\nAccount number: 4409912",
        'text/plain',
    );

    expect($outcome->signal)->toBe('routing_number_in_banking_context')
        ->and($outcome->signal)->not->toContain('021000021')
        ->and($outcome->signal)->not->toContain('4409912');
});

it('does not treat a run of zeroes as a routing number', function (): void {
    // Passes the checksum arithmetic and is a redaction, not a bank.
    expect(SensitiveContent::scan("Routing: 000000000\nAccount number: redacted", 'text/plain')->isRefused())
        ->toBeFalse();
});

it('refuses an encrypted PDF as unreadable rather than decoding it to noise', function (): void {
    $pdf = "%PDF-1.4\ntrailer\n<< /Encrypt 9 0 R /Root 1 0 R >>\n%%EOF";

    expect(ReadableText::from($pdf, 'application/pdf'))->toBeNull();
});
