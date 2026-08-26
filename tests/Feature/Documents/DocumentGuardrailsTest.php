<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Models\Deal;
use App\Models\Document;
use App\Support\Documents\DocumentStorage;
use App\Support\Documents\RefusedDocument;
use App\Support\Documents\UnsupportedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * F6.2, F6.3 and F6.7 at the one place that writes a file (#98, #99, #100).
 *
 * Screen Inventory: *"S51 and S53 carry legal weight, not just UX… neither can
 * be quietly softened later for being annoying."* These are the tests that
 * make softening it show up as a failure rather than as a diff nobody reads.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    [$this->team, $this->owner] = $this->teamWithOwner();
    $this->actingAsPerson($this->owner, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A **real** upload over a temp file, so `getMimeType()` runs `finfo` and the
 * scan reads genuine bytes. `Illuminate\Http\Testing\File` answers from the
 * filename and never touches the contents, so every assertion about
 * content-based behaviour written with `fake()` would pass over a code path
 * production does not take.
 */
function upload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');

    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

it('refuses a cheque before anything reaches the disk', function (): void {
    /*
     * PRD §4.6's four requirements, at the seam where the second one is either
     * true or not: *refuse and discard before anything is written to permanent
     * storage*. The assertion that matters is the empty disk, not the
     * exception — an exception thrown after a write is a refusal that kept the
     * file.
     */
    $cheque = upload('deposit.txt', "PAY TO THE ORDER OF Bosart Group\n⑆123456789⑆ 0001234567⑈");

    expect(fn () => app(DocumentStorage::class)->store($this->deal, $cheque, $this->owner, DocumentCategory::Receipt))
        ->toThrow(RefusedDocument::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

it('tells the person where the document belongs instead', function (): void {
    /*
     * Issue #99: *"what to do instead is the part that makes this acceptable
     * rather than infuriating."* A refusal that only prohibits reads as a bug.
     */
    $statement = upload('july.txt', "MONTHLY STATEMENT\nBeginning balance \$4,201.55\nEnding balance \$3,880.12");

    try {
        app(DocumentStorage::class)->store($this->deal, $statement, $this->owner, DocumentCategory::Other);

        $this->fail('The upload should have been refused.');
    } catch (RefusedDocument $refusal) {
        expect($refusal->getMessage())->not->toBe('')
            ->and($refusal->alternative())->not->toBe('')
            ->and($refusal->category->value)->toBe('bank_statement');
    }
});

it('never names the content in the refusal', function (): void {
    /*
     * #100 item 3 and PRD §9. An exception message is a string that reaches a
     * log by default, and a routing number in a log is the thing the refusal
     * existed to keep off the disk, written somewhere else instead.
     */
    $wire = upload('wire.txt', "Wire transfer instructions\nRouting: 021000021\nAccount number: 4409912");

    try {
        app(DocumentStorage::class)->store($this->deal, $wire, $this->owner, DocumentCategory::Other);

        $this->fail('The upload should have been refused.');
    } catch (RefusedDocument $refusal) {
        expect($refusal->getMessage())->not->toContain('021000021')
            ->and($refusal->getMessage())->not->toContain('4409912')
            ->and($refusal->signal)->not->toContain('021000021');
    }
});

it('keeps an ordinary working document, internal by default', function (): void {
    /*
     * F6.3: internal unless somebody says otherwise. The costs are not
     * symmetric — a document that should have been shared and was not is a
     * conversation; one that should not have been and was is not recoverable.
     */
    $report = upload('inspection.txt', "PROPERTY INSPECTION REPORT\n12 Oak Lane\nRoof: no visible damage.");

    $document = app(DocumentStorage::class)->store($this->deal, $report, $this->owner, DocumentCategory::InspectionReport);

    expect($document->visibility)->toBe(DocumentVisibility::Internal)
        ->and($document->category)->toBe(DocumentCategory::InspectionReport)
        ->and($document->scan_state)->toBe('clean')
        ->and($document->scanned_at)->not->toBeNull();

    Storage::disk(DocumentStorage::DISK)->assertExists($document->path);
});

it('records an image as not scanned rather than as clean', function (): void {
    /*
     * PRD §14.1 Q6, on the row. This build has no OCR, so an image was never
     * looked inside — and §14.3 names a photographed cheque as the single
     * largest liability in the product, which makes this exactly the file that
     * must not be recorded as having passed a check.
     */
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );

    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('scan.png', $png),
        $this->owner,
        DocumentCategory::Disclosure,
    );

    expect($document->scan_state)->toBe('not_scanned')
        ->and($document->wasScanned())->toBeFalse();
});

it('will not take an archive, which hides its contents from the scan', function (): void {
    /*
     * A refusal somebody can work around by exporting differently, which is
     * the right kind to make. An archive would put the scan in front of a
     * container rather than a document.
     */
    $zip = upload('bundle.zip', "PK\x03\x04".str_repeat("\0", 64));

    expect(fn () => app(DocumentStorage::class)->store($this->deal, $zip, $this->owner, DocumentCategory::Other))
        ->toThrow(UnsupportedDocument::class);

    expect(Document::query()->count())->toBe(0);
});

it('still refuses a PDF for the photo gallery, which takes photographs', function (): void {
    /*
     * The allowlist follows the **category**, not the caller. A `photo` that is
     * a PDF is a mistake somebody should be told about; a `disclosure` that is
     * a JPEG is a photographed document and ordinary.
     */
    $pdf = upload('report.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF");

    expect(fn () => app(DocumentStorage::class)->store($this->deal, $pdf, $this->owner, DocumentCategory::Photo))
        ->toThrow(UnsupportedDocument::class);
});

it('takes a PDF as a document', function (): void {
    /*
     * A sentence rather than a fragment, because `clean` now needs enough
     * letters to be believable — see `ReadableText::meaningful()`. The floor
     * exists so a handful of bytes surviving a bad decode cannot earn the word
     * "clean", and it errs toward `not_scanned`, which claims nothing. A real
     * disclosure clears it comfortably; the four-word fixture this used to be
     * did not, which is the floor working rather than the fixture being wrong.
     */
    $pdf = upload('disclosure.pdf', "%PDF-1.4\n4 0 obj\n<< /Length 200 >>\nstream\nBT (The seller discloses that the roof was replaced in 2019 and that the basement has flooded once since purchase.) Tj ET\nendstream\nendobj\n%%EOF");

    $document = app(DocumentStorage::class)->store($this->deal, $pdf, $this->owner, DocumentCategory::Disclosure);

    expect($document->mime_type)->toBe('application/pdf')
        ->and($document->scan_state)->toBe('clean');
});

it('records a short but genuine document as not scanned rather than clean', function (): void {
    // The floor's cost, stated rather than discovered. `not_scanned` claims
    // nothing, so erring this way is the safe direction — but it is a trade,
    // and a trade nobody wrote down is a bug report waiting to happen.
    $pdf = upload('note.pdf', "%PDF-1.4\n4 0 obj\n<< /Length 40 >>\nstream\nBT (Roof done) Tj ET\nendstream\nendobj\n%%EOF");

    $document = app(DocumentStorage::class)->store($this->deal, $pdf, $this->owner, DocumentCategory::Disclosure);

    expect($document->scan_state)->toBe('not_scanned');
});

it('records that a refusal happened, and nothing about what was in the file', function (): void {
    /*
     * PRD §4.6 and #100 item 3 both ask for it, and three docblocks in this
     * module claimed it while nothing wrote a line — round 1 of review found
     * that with `grep`, which is the right tool for a promise nobody had asked
     * to see.
     *
     * `reason_code`, never `reason`: `Redactor::SENSITIVE_KEY_PARTS` holds
     * `reason`, so a diagnostic under that key reaches an operator as
     * `[redacted]`. And asserted through `Redactor::context()` rather than
     * `Log::spy()`, because a spy intercepts above Monolog and cannot see the
     * redaction — every test would pass while the operator got nothing.
     */
    $statement = "FIRST MERIDIAN BANK Statement of Account\nAccount Number: 4419827733\n"
        ."Routing Number: 021000021\nBeginning Balance 4,201.55\nDeposits and Other Credits";

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($statement): bool {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);

            expect($message)->toBe('document.refused')
                ->and($context['reason_code'])->toBeString()
                ->and($context)->not->toHaveKey('reason')
                // Not the filename, not the matched string, not the bytes.
                ->and($encoded)->not->toContain('statement.txt')
                ->and($encoded)->not->toContain('021000021')
                ->and($encoded)->not->toContain($statement);

            return true;
        });

    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('statement.txt', $statement),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('sees a social security number however the form separated it', function (string $separated): void {
    /*
     * Round 1 of review: the pattern matched only `123-45-6789`. PDF text
     * extraction is exactly where that breaks — a form with boxed digits comes
     * out space-separated, and a word processor turns a typed hyphen into an
     * en dash.
     */
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('form.txt', "Applicant details\nSSN {$separated}\nDate of birth 1974-02-11"),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
})->with([
    'hyphens' => '412-55-8931',
    'spaces' => '412 55 8931',
    'en dashes' => "412\u{2013}55\u{2013}8931",
    'dots' => '412.55.8931',
]);

it('does not refuse an agent explaining the closing disclosure timeline', function (): void {
    /*
     * The false positive round 1 measured, and the one that would teach a team
     * the check is broken. One phrase is a *mention*; a lending packet repeats
     * its own vocabulary. The class's stated rule everywhere else is that a
     * single weak signal does not refuse — this arm was the exception.
     */
    $email = 'Hi Emily — the lender should send the Closing Disclosure three business '
        .'days before we sign, so if it has not arrived by Tuesday let me know and I will '
        .'chase them. Nothing for you to do until then.';

    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('note-to-emily.txt', $email),
        $this->owner,
        DocumentCategory::Correspondence,
    );

    expect($document->scan_state)->toBe('clean');
});

it('still refuses the form itself, on its own title', function (): void {
    // Nobody types "uniform residential loan application" in a covering note.
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('app.txt', 'Uniform Residential Loan Application. Borrower information follows.'),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('refuses a contract that has been signed, and keeps the one being negotiated', function (): void {
    /*
     * The distinction is the whole rule. PRD §1.1 puts this product
     * *alongside* the e-signature platform rather than in front of it, so the
     * unexecuted draft somebody is negotiating is exactly the document they
     * should be able to keep here. What belongs in CTM is the one that has
     * been signed.
     *
     * Round 1 of review found this category named in S51's warning and in the
     * help article with **no detector at all** — a refusal list with a
     * category nothing reaches is a promise the product does not keep.
     */
    $executed = 'RESIDENTIAL PURCHASE AGREEMENT. In witness whereof the parties have executed '
        ."this agreement.\nDocuSign Envelope ID: 9C41B7A2-33F0-4E19-9D2B-77A1C6E4B510\n"
        .'Electronically signed by Emily Bosart. Certificate of completion follows.';

    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('contract.txt', $executed),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);

    $draft = 'Draft residential purchase agreement for review. Buyer signature and seller '
        .'signature blocks are at the end. Emily — see paragraph 14, I have changed the '
        .'inspection window to ten days and left the financing contingency alone.';

    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('draft.txt', $draft),
        $this->owner,
        DocumentCategory::Correspondence,
    );

    expect($document->scan_state)->toBe('clean');
});

it('scans the shortest and most dangerous documents, whatever their length', function (string $label, string $content): void {
    /*
     * Round 2 of review, blocker 2. The confidence floor sat in front of the
     * scan, so a document below it came back `null` and was never looked at —
     * and the five shortest documents in the threat model are the five that
     * matter most. A MICR-only cheque has **zero** letters; the `micr_line`
     * check, documented as *"conclusive on its own"*, was unreachable for any
     * document whose only text is a MICR line.
     *
     * Confidence decides the label now. It never decides whether to look.
     */
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('short.txt', $content),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
})->with([
    'a MICR line alone' => ['micr', '⑆021000021⑆ 4419827733⑈ 1042'],
    'a one-line wire instruction' => ['wire', 'Wire to routing 021000021 account 4419827733'],
    'an SSN card' => ['ssn', 'SOCIAL SECURITY 412-55-8931'],
]);

it('sees a cheque the extractor split mid-word', function (): void {
    /*
     * Round 2's first blocker. A kerning pair splits `PAY` across two string
     * literals — `[(PA) -20 (Y TO THE ORDER OF …)] TJ` — and the extractor
     * rebuilds it as `PA Y TO THE ORDER OF`, which matches no phrase in the
     * list. Every phrase test now runs against a whitespace-**squashed** copy
     * as well, which is blind to where the producer chose to break.
     */
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('cheque.txt', "FIRST MERIDIAN BANK\nPA Y TO THE ORDER OF Bosart Group\nMemo: earnest money"),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('sees a statement the extractor column-aligned', function (): void {
    /*
     * The other shape: a justified line or a table cell arrives with runs of
     * spaces between words, and an identical statement was refused
     * single-spaced and passed as `clean` column-aligned. Whitespace is
     * collapsed before matching now.
     */
    $aligned = "FIRST   MERIDIAN   BANK\nAccount    Number:     4419827733\n"
        ."Routing    Number:     021000021\nBeginning     Balance      4,201.55\n"
        .'Deposits   and   Other   Credits';

    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('statement.txt', $aligned),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('does not read a tax proration line as a social security number', function (): void {
    /*
     * The cost of round 1's separator widening, which round 2 measured:
     * `1,204.55 2026` on a settlement statement matched the space-separated
     * form. Spaces are now their own pattern with a digit boundary either
     * side, so a decimal followed by a year no longer reads as an SSN.
     */
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('proration.txt', "Settlement figures\nCounty tax proration 1,204.55 2026 tax year\n"
            .'Seller credit at closing applies to the buyer side of the ledger.'),
        $this->owner,
        DocumentCategory::Other,
    );

    expect($document->scan_state)->toBe('clean');
});

it('sees a statement whose phrases the extractor split mid-word', function (): void {
    /*
     * The cheque test above exercises the inline phrase check; this one
     * exercises the shared `countOfEither()`, which every other phrase list
     * goes through. Both need the squashed form, and a mutation that removes
     * it from one must not pass because the other still has it.
     *
     * `Begi nning Balance` is what a kerning pair does to a heading.
     */
    $split = "FIRST MERIDIAN BANK\nBegi nning Balance 4,201.55\nEnd ing Balance 3,880.12\n"
        .'Depos its and Other Credits';

    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('split.txt', $split),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});
