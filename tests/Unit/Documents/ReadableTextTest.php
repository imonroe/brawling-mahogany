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

it('hands back a short decode rather than swallowing it', function (): void {
    /*
     * Round 2 of review: this floor used to live inside `from()`, so anything
     * below it came back `null` and was **never scanned**. The five shortest
     * documents in the threat model are the ones that cost — a MICR-only
     * cheque has zero letters, a one-line wire instruction seven.
     *
     * Extraction returns what it got. Confidence is a separate question, and
     * it decides the label rather than whether to look.
     */
    $pdf = pdfWith('ok', 'tj');

    expect(trim((string) ReadableText::from($pdf, 'application/pdf')))->toBe('ok')
        ->and(ReadableText::isConfident('ok'))->toBeFalse();
});

it('does not mistake armoured binary for prose', function (string $label, string $decoded): void {
    /*
     * Round 2's third blocker: `looksLikeContent()`'s printable ratio passes
     * ASCII-armoured binary, and letters alone are not language. An ASCII85
     * image stream yielded 7,021 letters on a page with no text, and a Type1
     * font header sixty.
     *
     * Structure is what separates them. Armoured binary is one enormous token
     * and a font header is a few long identifiers; prose is many short words
     * with spaces between them.
     */
    expect(ReadableText::isConfident($decoded))->toBeFalse();
})->with([
    'an ascii85 blob' => ['ascii85', str_repeat('9jqo^BlbD-BleB1DJ+*+F(f,q', 300)],
    'one long identifier' => ['identifier', str_repeat('abcdefghijklmnopqrstuvwxyz', 40)],
]);

it('never reaches the confidence question with a font header, because it is not a content stream', function (): void {
    /*
     * A Type1 font header is structurally indistinguishable from prose once it
     * gets as far as `isConfident()` — short space-separated tokens, sixty
     * letters. Piling a third heuristic on there was the wrong answer; the
     * distinguishing fact is one layer earlier.
     *
     * A content stream shows text, and shows it with `Tj`, `TJ`, `'` or `"`.
     * A font program, an image and an XMP packet do not.
     */
    $header = '/FontMatrix[0.001 0 0 0.001 0 0]readonly def /Encoding StandardEncoding def '
        .'/CharStrings 229 dict dup begin /space (nothing) put end';

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'2 0 obj<</Length '.strlen($header).">>stream\n".$header."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    expect(ReadableText::from($pdf, 'application/pdf'))->toBeNull();
});

it('does call real prose prose', function (): void {
    // The control. Without it the test above passes against a method that
    // returns false for everything.
    expect(ReadableText::isConfident(
        'The seller discloses that the roof was replaced in 2019 and that the '
        .'basement has flooded once since purchase.',
    ))->toBeTrue();
});

it('knows which types it can look inside', function (): void {
    expect(ReadableText::supports('application/pdf'))->toBeTrue()
        ->and(ReadableText::supports('text/plain'))->toBeTrue()
        ->and(ReadableText::supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue()
        ->and(ReadableText::supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue()
        // An image has nothing to read, and must never claim otherwise.
        ->and(ReadableText::supports('image/jpeg'))->toBeFalse();
});

it('will not call a document checked when it only read part of it', function (): void {
    /*
     * Extraction stops at `MAX_CHARACTERS`, so a document longer than that was
     * read in part — and a routing number on the last page of a partial read
     * is a routing number nobody looked at. Reporting `clean` there is the
     * same lie as reporting it over an unreadable scan, arriving from the
     * other end.
     *
     * Found while fixing round 2's PCRE finding: the large PDF that finally
     * *could* be read came back `clean` with its statement lines past the
     * truncation point.
     */
    $long = str_repeat('The property was inspected and the roof is sound. ', 20_000);

    $text = (string) ReadableText::from($long, 'text/plain');

    expect(mb_strlen($text))->toBe(ReadableText::MAX_CHARACTERS)
        ->and(ReadableText::isConfident($text))->toBeFalse();
});

it('does not eat the compression checksum when it happens to end in a newline', function (): void {
    /*
     * Round 3 of review. It was `trim($stream, "\r\n")`, and a zlib stream
     * ends in an Adler-32 checksum whose last byte is `0x0A` or `0x0D` about
     * **0.7%** of the time — measured, and matching the reviewer's 0.69%. The
     * trim ate part of the checksum, inflation failed, and the stream was
     * dropped in silence. A 20-page PDF loses at least one page a fifth of the
     * time; a bank statement whose statement page went missing stored as
     * `clean`.
     *
     * Compressed data is not text and cannot be tidied as though it were. The
     * raw bytes are tried first now, and exactly one line ending is removed
     * only if that fails.
     *
     * The stream is **searched for** rather than hand-written because the byte
     * in question is a function of the content: a fixture with a fixed string
     * would pin one Adler-32 and prove nothing about the rule.
     */
    $stream = null;
    $content = '';

    for ($attempt = 0; $attempt < 200_000; $attempt++) {
        $content = 'BT /F1 10 Tf 40 750 Td (Routing Number: 021000021 Account 4419827733 ref '
            .bin2hex(random_bytes(6)).") Tj ET\n";

        $candidate = (string) gzcompress($content, 9);
        $last = mb_substr($candidate, -1, 1, '8bit');

        if ($last === "\n" || $last === "\r") {
            $stream = $candidate;
            break;
        }
    }

    expect($stream)->not->toBeNull('no newline-terminated stream found in 200k attempts');

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'2 0 obj<</Length '.strlen((string) $stream)."/Filter/FlateDecode>>stream\n"
        .$stream."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    expect(ReadableText::from($pdf, 'application/pdf'))->toContain('021000021');

    // And the control: the old spelling really would have lost it.
    expect(@gzuncompress(trim((string) $stream, "\r\n")))->toBeFalse();
});

it('truncates a stream too big to hold rather than dropping it in silence', function (): void {
    /*
     * Round 5 of review, and round 1's blocker arriving through a fourth door.
     *
     * Round 4 bounded the inflation, because deflate compresses repetition
     * about a thousandfold and a 1MB PDF could fatal a 128MB process. What the
     * bound then did is what this measures: `gzuncompress($bytes, $max)`
     * returns **`false`** when the output exceeds `$max` — not a truncated
     * string — so a legitimate content stream over half a megabyte was
     * indistinguishable from an image and dropped entirely, with `$partial`
     * never set. The document came back short, complete-looking and
     * **confident**: `clean`, over pages nobody read.
     *
     * The fixture is deliberately *readable* text rather than a repetition
     * bomb, because the defect is about the legitimate case: this is roughly a
     * 150-page contract, well inside what `DocumentStorage::MAX_BYTES` accepts.
     */
    $sentence = 'The seller shall deliver the executed disclosure to the buyer within three business days. ';
    $content = "BT /F1 10 Tf 40 750 Td (Routing Number: 021000021 ) Tj ET\n"
        .'BT /F1 10 Tf 40 730 Td ('.str_repeat($sentence, 8_000).") Tj ET\n";

    expect(mb_strlen($content))->toBeGreaterThan(ReadableText::MAX_CHARACTERS);

    $stream = (string) gzcompress($content, 9);

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'2 0 obj<</Length '.strlen($stream)."/Filter/FlateDecode>>stream\n"
        .$stream."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    $text = ReadableText::from($pdf, 'application/pdf');

    // Read, not discarded — the beginning of the document is really there.
    expect($text)->not->toBeNull()
        ->and($text)->toContain('021000021')
        // And *said* to be short of the end, which is the half that was missing.
        ->and(ReadableText::wasPartial())->toBeTrue()
        ->and(ReadableText::isConfident($text))->toBeFalse();

    // The control: the spelling this replaced threw the whole stream away.
    expect(@gzuncompress($stream, ReadableText::MAX_CHARACTERS))->toBeFalse();
});

it('will not call a Word document checked when one part of it ran past the ceiling', function (): void {
    /*
     * The same door, one file format along. `ZipArchive::getFromIndex()` takes
     * a length and returns the part **cut** at it, so a single
     * `word/document.xml` over `MAX_CHARACTERS` — a long disclosure packet, or
     * a workbook with a large shared-strings table — was read in part and
     * reported whole.
     *
     * The PDF side had two guards against this (the stream budget and the
     * character ceiling) and the OOXML side had one, which covered the case of
     * *many* parts and not the case of one large one.
     */
    $sentence = 'The seller shall deliver the executed disclosure to the buyer within three business days. ';
    $long = str_repeat($sentence, 8_000);

    expect(mb_strlen($long))->toBeGreaterThan(ReadableText::MAX_CHARACTERS);

    $docx = officeWith('Routing Number: 021000021 '.$long, 'word/document.xml');

    $text = ReadableText::from(
        $docx,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    expect($text)->toContain('021000021')
        ->and(ReadableText::wasPartial())->toBeTrue()
        ->and(ReadableText::isConfident($text))->toBeFalse();
});

it('walks a large PDF instead of copying it, so a 15MB upload fits in memory', function (): void {
    /*
     * The reason the image can raise `upload_max_filesize` to the 15MB this
     * product says it accepts.
     *
     * `preg_split` on the stream delimiter fixed round 2's backtracking
     * finding, and round 5 of review pointed out what it cost instead: **a
     * second copy of the whole file, cut up**, and then a third as each body
     * is taken out of its chunk. Measured on the fixture below — a realistic
     * 15MB PDF, forty small text streams among a hundred and fifty
     * incompressible image ones — the split form peaks at **52.3MB** where
     * walking the file with `strpos` peaks at **24.3MB**, which is the file
     * itself and nothing above it. The overhead is a multiple of the upload,
     * so it grows with exactly the limit that was about to be raised.
     *
     * Run in a **subprocess under a real limit**, because that is the only
     * observation that distinguishes the two: `memory_get_peak_usage()` inside
     * this process is monotonic and already carries every fixture Pest has
     * built, so an in-process assertion passes against the defect. 40MB sits
     * between the two measurements with room on both sides.
     */
    $content = "BT /F1 10 Tf 40 750 Td (Routing Number: 021000021 ) Tj ET\n";
    $stream = (string) gzcompress($content, 9);

    $pdf = (string) tempnam(sys_get_temp_dir(), 'bigpdf');
    $handle = fopen($pdf, 'wb');

    expect($handle)->not->toBeFalse();

    fwrite($handle, "%PDF-1.4\n");

    for ($object = 0; $object < 40; $object++) {
        fwrite($handle, $object.' 0 obj<</Length '.strlen($stream)."/Filter/FlateDecode>>stream\n"
            .$stream."\nendstream endobj\n");
    }

    /*
     * Incompressible, because a scanned page is: a fixture padded with a run
     * of one byte would be a file the allocator never has to hold.
     */
    for ($image = 0; $image < 150; $image++) {
        fwrite($handle, "99 0 obj<</Subtype/Image/Length 100000>>stream\n"
            .random_bytes(100_000)."\nendstream endobj\n");
    }

    fwrite($handle, "trailer<</Root 1 0 R>>\n%%EOF");
    fclose($handle);

    expect(filesize($pdf))->toBeGreaterThan(14 * 1024 * 1024);

    $script = (string) tempnam(sys_get_temp_dir(), 'pdfmem');
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1];
        $text = App\Support\Documents\ReadableText::from((string) file_get_contents($argv[2]), 'application/pdf');
        echo str_contains((string) $text, '021000021') ? 'READ' : 'MISSED';
        PHP);

    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=40M', $script, base_path('vendor/autoload.php'), $pdf],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($process)->not->toBeFalse();

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    @unlink($script);
    @unlink($pdf);

    expect($status)->toBe(0, "the scan did not survive a 40MB limit: {$stderr}")
        // And it read the document, rather than surviving by giving up on it.
        ->and($stdout)->toBe('READ');
});
