<?php

declare(strict_types=1);

use App\Support\Documents\ReadableText;

/**
 * What the scanner can actually read (issues #99, #100 · round 1 of review).
 *
 * The corpus in `SensitiveContentTest` measures the **patterns**, and round 1
 * of review made the fair criticism that it is fifteen plain-text strings
 * written against the rules that catch them — it can never see an extraction
 * failure, because it never extracts anything. This file is the other half:
 * the same sensitive text, wrapped the way real producers wrap it.
 *
 * A document that cannot be read is not a document that is clean. Every case
 * here asserts which of those two answers came back, because conflating them
 * is the defect that put a `clean` badge over an unread bank statement.
 */
const STATEMENT = 'FIRST MERIDIAN BANK Statement of Account. Account Number: 4419827733 '
    .'Routing Number: 021000021 Beginning Balance 4,201.55 Ending Balance 3,880.12 '
    .'Deposits and Other Credits. Checks and Other Debits. Member FDIC.';

/**
 * A PDF with a genuinely deflated content stream, in one producer's operator
 * form. Deflated, not pretend-deflated: the bug this file exists about was a
 * reader that guessed at compression from the compressed bytes.
 */
function pdfWith(string $text, string $form): string
{
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

    $content = match ($form) {
        // The simple form, and the only one the first version handled.
        'tj' => "BT /F1 10 Tf 40 750 Td ({$escaped}) Tj ET\n",
        // What Word, LibreOffice and most report generators emit for ordinary
        // justified text — kerning numbers between the literals.
        'tj_array' => 'BT /F1 10 Tf 40 750 Td ['
            .implode(' -20 ', array_map(fn (string $word): string => '('.$word.')', explode(' ', $escaped)))
            ."] TJ ET\n",
        // What a subset font writes.
        'hex' => 'BT /F1 10 Tf 40 750 Td <'.bin2hex($text)."> Tj ET\n",
        default => '',
    };

    $stream = (string) gzcompress($content, 9);

    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'2 0 obj<</Length '.strlen($stream)."/Filter/FlateDecode>>stream\n"
        .$stream."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
}

function officeWith(string $text, string $part): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'ox');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString(
        $part,
        '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
        .implode('', array_map(fn (string $w): string => "<w:t>{$w}</w:t>", explode(' ', $text)))
        .'</w:body></w:document>',
    );
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

it('reads a statement out of every form a real producer writes', function (string $form): void {
    /*
     * The `tj_array` case is the one that matters most: it is what a word
     * processor emits for ordinary text, so missing it meant missing exactly
     * the documents most likely to *be* a bank statement.
     */
    $text = ReadableText::from(pdfWith(STATEMENT, $form), 'application/pdf');

    expect($text)->not->toBeNull()
        ->and($text)->toContain('021000021')
        ->and($text)->toContain('Account Number');
})->with(['tj', 'tj_array', 'hex']);

it('reads a statement out of a Word document', function (): void {
    // These are in the upload allowlist, so "cannot look inside" meant a bank
    // statement exported from Word stored unscanned while the help article
    // told users the only thing that slips past is a photograph.
    $text = ReadableText::from(
        officeWith(STATEMENT, 'word/document.xml'),
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    expect($text)->not->toBeNull()->and($text)->toContain('021000021');
});

it('does not run words together across XML runs', function (): void {
    // `<w:t>Routing</w:t><w:t>Number</w:t>` must not become "RoutingNumber",
    // or every phrase match in the scanner misses.
    $text = (string) ReadableText::from(
        officeWith('Routing Number 021000021 Member FDIC deposits and credits', 'word/document.xml'),
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    expect($text)->toContain('Routing Number');
});

it('refuses to call unreadable bytes readable, however many it finds', function (): void {
    /*
     * **The blocker.** Deflate output is high-entropy, so `Tj` and `TJ` turn
     * up in it by chance — round 1 measured 71% of ~60KB streams. The reader
     * used that as its test for "uncompressed", handed the compressed bytes to
     * the operator regex, matched a few bytes of noise, and a non-empty result
     * was recorded `clean`.
     *
     * Forty of them, because the original defect showed up 2 times in 20.
     */
    $clean = 0;

    for ($i = 0; $i < 40; $i++) {
        $blob = random_bytes(60_000);

        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
            .'2 0 obj<</Length '.strlen($blob).">>stream\n".$blob."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        if (ReadableText::from($pdf, 'application/pdf') !== null) {
            $clean++;
        }
    }

    expect($clean)->toBe(0);
});

it('says nothing rather than a little, when a decode yields a handful of bytes', function (): void {
    // The floor under the word "clean". A stream that decoded to three
    // characters was read in no useful sense, and `not_scanned` is the honest
    // answer — the same one a photograph of a cheque gets.
    $pdf = pdfWith('ok', 'tj');

    expect(ReadableText::from($pdf, 'application/pdf'))->toBeNull();
});

it('knows which types it can look inside', function (): void {
    expect(ReadableText::supports('application/pdf'))->toBeTrue()
        ->and(ReadableText::supports('text/plain'))->toBeTrue()
        ->and(ReadableText::supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue()
        ->and(ReadableText::supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue()
        // An image has nothing to read, and must never claim otherwise.
        ->and(ReadableText::supports('image/jpeg'))->toBeFalse();
});
