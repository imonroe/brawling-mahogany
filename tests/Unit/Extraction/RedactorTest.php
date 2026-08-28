<?php

declare(strict_types=1);

use App\Support\Extraction\Redaction\RedactedDocument;
use App\Support\Extraction\Redaction\Redactor;

/**
 * F10.5, in both directions (#114 · PRD §4.10, §9).
 *
 * PRD §9: *"No document reaches a third-party model without redaction."* The
 * type system makes that structural — `ExtractionProvider::extract()` takes a
 * `RedactedDocument` and nothing else produces one — so what is left for a test
 * is the part the types cannot decide: **what** comes out.
 *
 * That is two questions, and #114 is emphatic that the second is the one that
 * gets forgotten:
 *
 * > *"Redaction cannot destroy the dates. A redactor that masks a purchase
 * > price or a deadline has broken the feature."*
 *
 * So the file is written as a pair. The first half proves a routing number, an
 * account number, a social security number, a MICR line, a card number and a
 * government ID number are gone. The second half — the longer one, deliberately
 * — proves every date format a Colorado contract uses, every figure on its
 * price page, and the ordinary reference numbers a property carries come out
 * **byte for byte unchanged**. A redactor that passed only the first half would
 * be a redactor nobody could ship.
 *
 * Pure: no database, no team, no provider. `Redactor` takes a string and
 * returns a value object, which is why this is a Unit test rather than a
 * Feature one (docs/Testing.md §2 — *"if it makes a row, it is a Feature
 * test"*).
 */
function redacted(string $text): RedactedDocument
{
    return (new Redactor)->redact($text);
}

it('takes the identifiers a contract carries', function (string $label, string $text, string $placeholder, string $secret): void {
    /*
     * The claim this class is entitled to make, stated in its own docblock:
     * *"a routing number, an account number, a card number, a social security
     * number and a government ID number will not be in the text that leaves."*
     * One case per member of that list, and each asserts both halves — the
     * digits are gone, **and** the named placeholder is there.
     *
     * The placeholder matters as much as the removal. `Redactor` argues for a
     * named marker over a run of `X`s because the model is still being asked to
     * read the document, and `[redacted: account number]` tells it what it is
     * looking at where `XXXXXXXXX` reads as a blank somebody forgot to fill in
     * and invites it to hallucinate around.
     */
    $result = redacted($text);

    expect($text)->toContain($secret)
        ->and($result->text)->not->toContain($secret)
        ->and($result->text)->toContain($placeholder);
})->with([
    'a labelled routing number' => [
        'routing',
        "Beneficiary Bank: Clear Creek Valley Bank\nRouting Number: 123456789\n",
        '[redacted: routing number]',
        '123456789',
    ],
    'a labelled account number' => [
        'account',
        "The beneficiary is Ralston Creek Title and Escrow LLC.\nAccount Number: 0004567891\n",
        '[redacted: account number]',
        '0004567891',
    ],
    /*
     * Punctuated, and unlabelled. `Redactor` treats this shape as conclusive on
     * its own — no date, price or parcel number in an American contract is
     * written three-two-four with hyphens — which is the one place a bare shape
     * is allowed to fire without words beside it.
     */
    'a social security number, punctuated' => [
        'ssn',
        "Applicant details\n412-55-8931\nDate of birth 1974-02-11",
        '[redacted: social security number]',
        '412-55-8931',
    ],
    /*
     * The same number with the punctuation lost, which is what a boxed form
     * looks like after text extraction. Nine bare digits are a routing number,
     * a parcel number or a phone number just as easily, so this one is refused
     * on the **label** rather than on the shape.
     */
    'a social security number, bare and labelled' => [
        'ssn-bare',
        "Applicant details\nSSN: 412558931\n",
        '[redacted: government id]',
        '412558931',
    ],
    /*
     * The E-13B symbols off the bottom of a cheque. Conclusive on its own — they
     * exist nowhere else — and taken as one run, because a MICR line is a
     * routing and an account number with no label between them and every later
     * rule would take only part of it.
     */
    'a MICR line' => [
        'micr',
        "PAY TO THE ORDER OF Bosart Group\n⑆021000021⑆ 4419827733⑈ 1042\nMemo: earnest money",
        '[redacted: micr line]',
        '4419827733',
    ],
    /*
     * The other rule that fires without words: Luhn is a real check rather than
     * a shape, and a contract has very few sixteen-digit runs to begin with.
     */
    'a card number that passes Luhn' => [
        'card',
        'Card on file 4111 1111 1111 1111 for the holding deposit.',
        '[redacted: card number]',
        '4111 1111 1111 1111',
    ],
    'a labelled driver licence number' => [
        'licence',
        'Driver License Number: 940381756',
        '[redacted: government id]',
        '940381756',
    ],
    'a labelled passport number' => [
        'passport',
        'Passport No. 512348907',
        '[redacted: government id]',
        '512348907',
    ],
]);

it('leaves the dates and the money exactly as they were', function (string $label, string $text): void {
    /*
     * #114, and the sentence the whole feature hangs on: *"a redactor that masks
     * a purchase price or a deadline has broken the feature."*
     *
     * Asserted as **identity** rather than as `toContain`, on purpose. A rule
     * that fired and then happened to put the digits back would pass a
     * containment check; only `toBe($text)` says nothing at all happened. The
     * empty report beside it is the second half of the same claim: a rule that
     * matched and was vetoed still counts as untouched output here, and the
     * count is what tells the two apart on the row afterwards.
     *
     * The ISO date is the load-bearing case. It is the one fixture below that a
     * pattern genuinely *matches* — `2026-03-28` is a digit run with hyphens in
     * it — and it survives only because `isProtected()` vetoes it. Delete that
     * veto and this case alone goes red.
     */
    $result = redacted($text);

    expect($result->text)->toBe($text)
        ->and($result->report->isEmpty())->toBeTrue()
        ->and($result->report->total())->toBe(0);
})->with([
    'a date in slashes' => ['slashed', 'Closing Date 03/28/2026'],
    'a date in ISO' => ['iso', 'Closing Date 2026-03-28'],
    'a date in words' => ['written', 'Closing Date March 28, 2026'],
    'a purchase price' => ['price', 'Purchase Price $685,000.00'],
    'a purchase price with no currency mark' => ['bare-price', 'Purchase Price 685,000'],
    'an earnest money amount' => ['earnest', 'Earnest Money $15,000.00'],
    'a percentage' => ['percent', 'Interest at 6.25% per annum'],
    'a paragraph number' => ['paragraph', 'See Section 10.3 above'],
    'a day count' => ['days', 'Objection within 10 days of MEC'],
    'a square footage' => ['sqft', '1,840 square feet on two levels'],
    'an MLS number' => ['mls', 'MLS Number 8207764'],
    'a parcel number' => ['parcel', 'Schedule Number 4482-05-112-0120'],
    /*
     * Nine bare digits with nothing beside them. One nine-digit run in ten
     * passes the ABA checksum, so a rule that fired on the shape would take a
     * plat reference, an MLS number and a phone number with the punctuation
     * lost — which is the false-positive spiral `SensitiveContent` measured over
     * three rounds one module along.
     */
    'a bare nine-digit run' => ['nine-digits', 'Reference 490712104 on the plat'],
    'a phone number' => ['phone', 'Telephone (303) 555-0142 to confirm'],
]);

it('does not read an ordinary number as an identifier because of a word nearby', function (string $label, string $text): void {
    /*
     * The label window is a distance, so whatever it reaches has to be matched
     * as a **word** and not as a substring. Three of the captions are short
     * enough for that to be the difference between a guardrail and a nuisance,
     * and all three of those strings occur constantly in the documents this
     * product reads:
     *
     * - `tin`, for taxpayer identification number, is inside *lighting*,
     *   *listing*, *existing*, *heating* and *writing* — and every Colorado
     *   contract has an inclusions clause listing the lighting fixtures.
     * - `aba` is inside *tax abatement* and *Alabama*.
     * - `iban` is inside a surname.
     *
     * CLAUDE.md records the same defect being found and fixed in
     * `SensitiveContent`, where it cost a **50% false-positive rate** on
     * legitimate paperwork — *"`aba` inside 'tax abatement' and 'Alabama'"* —
     * and this class inherits the finding rather than rediscovering it. What
     * makes it worth its own case here is the direction of the damage: a
     * substring match takes the **purchase price** in the third fixture and the
     * parcel number that sits on the property line of every contract in
     * `tests/Corpus` in the first two, which is #114's *"a redactor that masks
     * a purchase price or a deadline has broken the feature"* exactly.
     *
     * The fixtures are lifted from the corpus rather than invented: the first is
     * 0013's property clause, whose *lighting* is two lines below the schedule
     * number, and the second is 0009's signature block.
     */
    expect(redacted($text)->text)->toBe($text);
})->with([
    'a parcel number two lines above the word lighting' => [
        'lighting',
        "known as 15422 East Vassar Avenue,\nAurora, CO 80014. Schedule Number 1978-07-421-0210\n\n"
            .'2.5 Inclusions. All attached lighting and plumbing fixtures, the chimney damper.',
    ],
    'an MLS number beside the listing brokerage' => [
        'listing',
        'MLS Number 8207764. Listing Brokerage: Olde Town Arvada Realty.',
    ],
    'a settlement figure beside a tax abatement' => [
        'abatement',
        'Settlement credit 726500 and tax abatement applies through 2029.',
    ],
]);

it('counts what it took and carries none of it', function (): void {
    /*
     * `RedactionReport`'s whole argument: *"counts only. A report carrying the
     * values would be a second copy of exactly the data the redactor exists to
     * stop travelling, and it would live in a JSONB column that no
     * `Redactor::SENSITIVE_KEY_PARTS` covers."*
     *
     * So this asserts the numbers **and** encodes the whole report and looks for
     * the digits in it — because the report is stored on `extractions
     * .redaction_report` as JSON, and that is the shape a leak would take.
     */
    $wire = "Wire instructions for the earnest money deposit.\n"
        ."Beneficiary Bank: Clear Creek Valley Bank\n"
        ."Routing Number: 123456789\n"
        ."\n"
        ."The beneficiary is Ralston Creek Title and Escrow LLC.\n"
        ."Account Number: 0004567891\n";

    $report = redacted($wire)->report;

    expect($report->counts)->toBe(['account_number' => 1, 'routing_number' => 1])
        ->and($report->total())->toBe(2)
        ->and($report->isEmpty())->toBeFalse();

    $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('123456789')
        ->and($encoded)->not->toContain('0004567891')
        ->and($encoded)->not->toContain('Ralston');
});

it('keeps the whitespace around what it took', function (): void {
    /*
     * The reason is in `Redactor::replace()` and it is a reading failure rather
     * than a cosmetic one: *"a placeholder that eats the newline before it joins
     * two table rows together, and the model then reads a date from the wrong
     * line."* A contract's dates arrive as a fixed-width table, so a lost line
     * break is a lost deadline attributed to the row above it.
     *
     * The MICR rule is the one that would do it — its pattern is greedy over
     * whitespace by design, so the match really does begin and end with the
     * newlines either side of the cheque line.
     */
    $cheque = "PAY TO THE ORDER OF Bosart Group\n⑆021000021⑆ 4419827733⑈ 1042\nClosing Date August 28, 2026";

    $result = redacted($cheque);

    expect($result->text)->toBe(
        "PAY TO THE ORDER OF Bosart Group\n[redacted: micr line]\nClosing Date August 28, 2026",
    )->and(explode("\n", $result->text))->toHaveCount(3);
});

it('can tell a redacted document from an untouched one', function (): void {
    /*
     * The positive control, and it is not decoration. Every assertion in the
     * first half of this file is of the form *"this string is not in the
     * output"*, which passes just as happily over a redactor that returned an
     * empty string, over a fixture that never carried the digits, and over a
     * `Redactor` whose patterns have quietly stopped matching anything at all.
     *
     * docs/Testing.md: *"a `0` or a `null` is the answer a broken feature gives
     * too."* So this one case does all four things in order — the fixture really
     * carries the identifier, the identifier really goes, an ordinary number
     * beside it really stays, and exactly one thing was counted.
     *
     * The middle sentence is doing work: it is longer than the 48-character
     * label window, so *routing* on the first line cannot reach the reference
     * number on the third. Shorten it and this test starts passing for the wrong
     * reason.
     */
    $document = "Routing Number: 123456789\n"
        ."Buyer may inspect the Property at Buyer's own expense before the deadline.\n"
        .'MLS Number 8207764';

    expect($document)->toContain('123456789')
        ->and($document)->toContain('8207764');

    $result = redacted($document);

    expect($result->text)->not->toContain('123456789')
        ->and($result->text)->toContain('[redacted: routing number]')
        ->and($result->text)->toContain('8207764')
        ->and($result->report->counts)->toBe(['routing_number' => 1]);
});

it('hands back a document that reports its own length and emptiness', function (): void {
    /*
     * `RedactedDocument` is what `PerformExtraction` writes to
     * `extractions.redacted_text` and what the provider is handed, and both of
     * these are read before anything leaves — `isEmpty()` decides whether there
     * is anything to send at all. A value object whose two accessors were never
     * exercised is two lines nothing holds.
     */
    expect(redacted('')->isEmpty())->toBeTrue()
        ->and(redacted("   \n ")->isEmpty())->toBeTrue()
        ->and(redacted('Closing Date 2026-03-28')->isEmpty())->toBeFalse()
        ->and(redacted('Closing Date 2026-03-28')->length())->toBe(23);
});
