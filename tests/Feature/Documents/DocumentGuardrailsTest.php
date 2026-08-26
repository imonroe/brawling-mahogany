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
    $pdf = upload('disclosure.pdf', "%PDF-1.4\n4 0 obj\n<< /Length 40 >>\nstream\nBT (Roof replaced in 2019) Tj ET\nendstream\nendobj\n%%EOF");

    $document = app(DocumentStorage::class)->store($this->deal, $pdf, $this->owner, DocumentCategory::Disclosure);

    expect($document->mime_type)->toBe('application/pdf')
        ->and($document->scan_state)->toBe('clean');
});
