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

it('sees a column-aligned social security number', function (): void {
    /*
     * Round 3 of review: the SSN branch was the **one test not routed through
     * the normalisers**, so `123  45  6789` — which the previous revision
     * refused — came back `clean`. That is the worst possible answer: a
     * positive claim of "text read, nothing refused" over a social security
     * number.
     */
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('form.txt', "Applicant\nSSN     412   55   8931\nDate of birth 1974-02-11"),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('keeps the paperwork a team actually handles', function (string $label, string $content): void {
    /*
     * Round 3 measured a **50% false-positive rate** on legitimate documents,
     * and the engine was the routing rule: the ABA checksum passes one
     * nine-digit run in ten, so every parcel number and MLS reference had a
     * one-in-ten chance of arming it, paired with a `BANKING_CONTEXT` list
     * that matched by substring — `aba` inside "tax abatement" and "Alabama".
     *
     * The wire fraud advisory is the case that settles it: brokerages are
     * **required** to circulate it, and a scanner that refuses it is a scanner
     * a team learns to work around.
     *
     * A routing number has to be *labelled* now, not merely present.
     */
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload($label.'.txt', $content),
        $this->owner,
        DocumentCategory::Correspondence,
    );

    expect($document->scan_state)->toBe('clean');
})->with([
    'a wire fraud advisory' => ['advisory', 'IMPORTANT NOTICE ABOUT WIRE FRAUD. Criminals send fraudulent wire '
        .'transfer instructions that appear to come from your agent or the title company. Never send '
        .'funds based on emailed instructions alone. Call the closing office on a number you already '
        .'have and confirm the details verbally before any transfer is initiated.'],
    'a settlement statement' => ['settlement', 'Settlement statement. Parcel number 490712104 in the county '
        .'records. Tax abatement applies through 2029. Seller credit at closing 4,201.55 and the '
        .'buyer side of the ledger carries the recording fee and the survey.'],
    'an MLS printout' => ['mls', 'MLS 218840173. Single family, 3 bd 2.5 ba, 1,840 sqft, built 1962. '
        .'Listing agent notes: the seller has replaced the roof and the furnace since purchase, and '
        .'the basement has flooded once.'],
    'a lease addendum' => ['lease', 'Addendum to residential lease. The tenant shall maintain renter '
        .'insurance throughout the term. Rent is due on the first. Late fees accrue after the fifth '
        .'day and are capped as set out in the Alabama statute referenced above.'],
]);

it('still refuses a statement that labels its routing number', function (): void {
    // The control. Without it the test above passes against a rule that
    // refuses nothing at all.
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('statement.txt', "FIRST MERIDIAN BANK\nRouting Number: 021000021\n"
            ."Account Number: 4419827733\nBeginning Balance 4,201.55"),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});

it('does not read a numeric column as a social security number', function (): void {
    /*
     * Round 4 of review measured **20.8%** of legitimate documents refused,
     * every one of them as *Government ID* — telling an inspector to take his
     * radon report "to whoever asked for your identity documents".
     *
     * The oscillation is the finding. Round 1 matched only `123-45-6789`;
     * round 2 widened and started refusing a tax proration line; round 3
     * narrowed and routed it through the collapsed text, which caught aligned
     * forms and every three-two-four numeric column with them. Narrowing and
     * widening the same pattern trades one direction for the other, because
     * the **shape alone is not the signal**.
     *
     * The punctuated form still stands alone. The spaced form needs a label,
     * exactly as a routing number does.
     */
    $estimate = "Repair estimate\nItem   Qty   Unit   Total\nRoof    124    22   2728\n"
        ."Gutter   40    18    720\nSubtotal 3448";

    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('estimate.txt', $estimate),
        $this->owner,
        DocumentCategory::Other,
    );

    expect($document->scan_state)->toBe('clean');
});

it('still refuses a form that says what its digits are', function (string $label, string $content): void {
    // The control for the test above, in both directions: punctuated needs no
    // label, spaced does, and an aligned form carries one.
    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload($label.'.txt', $content),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
})->with([
    'punctuated, unlabelled' => ['hyphen', "Applicant details\n412-55-8931\nDate of birth 1974-02-11"],
    'spaced, labelled' => ['spaced', "Applicant\nSocial Security Number 412 55 8931"],
    'spaced, labelled and aligned' => ['aligned', "Applicant\nSSN     412   55   8931"],
]);

it('will not call a document checked when the stream budget ran out', function (): void {
    /*
     * Round 4's third blocker, and the second of its three doors. Only the
     * `MAX_CHARACTERS` one was visible to the confidence check, so a PDF whose
     * statement page sat past the stream budget came back `clean` — a document
     * nobody finished reading, labelled checked.
     */
    $streams = '';

    for ($page = 0; $page < 600; $page++) {
        $content = "BT /F1 10 Tf 40 750 Td (Page {$page} of ordinary narrative about the property.) Tj ET\n";
        $compressed = (string) gzcompress($content, 9);

        $streams .= '2 0 obj<</Length '.strlen($compressed)."/Filter/FlateDecode>>stream\n"
            .$compressed."\nendstream endobj\n";
    }

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$streams."trailer<</Root 1 0 R>>\n%%EOF";

    $document = app(DocumentStorage::class)->store(
        $this->deal,
        upload('long.pdf', $pdf),
        $this->owner,
        DocumentCategory::Other,
    );

    expect($document->scan_state)->toBe('not_scanned');
});

it('refuses a decompression bomb rather than dying on it', function (): void {
    /*
     * Round 4's second blocker. `gzuncompress` with no `$max_length` fatals a
     * 128MB process — PHP's default, and the FrankenPHP base image installs no
     * `php.ini`. `@` suppresses a warning, not an out-of-memory, so nothing
     * downstream can catch it: the request dies, the controller's `catch`
     * never runs, and an upload endpoint anybody can reach becomes a way to
     * kill a worker.
     *
     * ## Why this runs in a subprocess
     *
     * Two in-process assertions were tried first and **neither could tell the
     * fix from the defect**. The scan state is `not_scanned` either way — an
     * unbounded inflate expands the whole 200MB and the caller truncates it —
     * and `memory_get_peak_usage()` is monotonic across the run, so an earlier
     * test's peak swallows the delta.
     *
     * What actually separates them is whether a process with production's
     * memory limit survives, so that is what is measured. `-d memory_limit`
     * is the one honest way to reproduce the condition the web SAPI dies of
     * from a CLI that does not.
     */
    $bomb = (string) gzcompress(str_repeat('A', 200 * 1024 * 1024), 9);

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'2 0 obj<</Length '.strlen($bomb)."/Filter/FlateDecode>>stream\n"
        .$bomb."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    $path = (string) tempnam(sys_get_temp_dir(), 'bomb');
    file_put_contents($path, $pdf);

    $script = 'require "'.base_path('vendor/autoload.php').'";'
        .'$t = App\Support\Documents\ReadableText::from(file_get_contents($argv[1]), "application/pdf");'
        .'echo "survived";';

    exec(
        escapeshellcmd(PHP_BINARY).' -d memory_limit=128M -r '.escapeshellarg($script)
            .' '.escapeshellarg($path).' 2>&1',
        $output,
        $status,
    );

    @unlink($path);

    expect($status)->toBe(0, 'the extractor died on a 1MB upload: '.implode(' ', $output))
        ->and(implode(' ', $output))->toContain('survived');
});

it('does not let images spend the budget meant for text', function (): void {
    /*
     * The budget was counted before inflation was attempted, so a PDF whose
     * first four hundred streams are photographs spent the whole allowance on
     * bytes with no text in them and never reached the page that mattered.
     *
     * Five hundred image streams, then the statement. A refusal is only
     * possible if the images cost nothing.
     */
    $streams = '';

    for ($image = 0; $image < 500; $image++) {
        $blob = random_bytes(300);

        $streams .= '2 0 obj<</Length '.strlen($blob).">>stream\n".$blob."\nendstream endobj\n";
    }

    $content = "BT /F1 10 Tf 40 750 Td (Routing Number: 021000021 Account Number: 4419827733) Tj ET\n";
    $compressed = (string) gzcompress($content, 9);

    $streams .= '2 0 obj<</Length '.strlen($compressed)."/Filter/FlateDecode>>stream\n"
        .$compressed."\nendstream endobj\n";

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$streams."trailer<</Root 1 0 R>>\n%%EOF";

    expect(fn (): mixed => app(DocumentStorage::class)->store(
        $this->deal,
        upload('scanned.pdf', $pdf),
        $this->owner,
        DocumentCategory::Other,
    ))->toThrow(RefusedDocument::class);
});
