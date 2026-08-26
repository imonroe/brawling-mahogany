<?php

declare(strict_types=1);

namespace App\Support\Documents;

use ZipArchive;

/**
 * What text this product can read out of an uploaded file, if any.
 *
 * Split from {@see SensitiveContent} because the two answer different
 * questions, and conflating them is how a scan comes to claim more than it
 * does. This one answers *"can we see inside this file at all"*; the scanner
 * answers *"does what we can see look like a financial instrument"*. A file
 * this returns null for was **never scanned**, and PRD §14.1 Q6 turns on
 * saying so rather than reporting a clean result.
 *
 * ## What it can read, and what it cannot
 *
 * Plain text, CSV, and the text layer of a PDF. **Not images**, and not a PDF
 * that is only images — this application has no OCR and no image-text
 * dependency, and pretending otherwise would be the overclaim PRD §14.3 warns
 * about in as many words: *"do not let marketing copy claim more than section
 * 8.4 actually delivers."*
 *
 * That gap is the important one, because PRD §14.3 also names a photographed
 * cheque as the single largest liability in the product — and a photograph is
 * exactly what this cannot read. The scan covers the **document** cases (a
 * bank statement PDF, a lending packet); F6.6's warning is what covers the
 * photograph, which is why F6.6 is a Must and F6.7 only a Should.
 *
 * ## The PDF reader is deliberately shallow
 *
 * It inflates each `stream` object and pulls the string literals out of the
 * text operators. That handles a PDF produced by a bank, a lender or a word
 * processor, and it does not handle every PDF ever written — an encrypted
 * one, an unusual filter, a font with a custom encoding. Those come back as
 * unreadable rather than as empty, so they are reported as *not scanned*
 * instead of as *clean*.
 */
final class ReadableText
{
    /** Beyond this, stop reading. A scan is not a parser and a huge file is not worth the memory. */
    public const MAX_CHARACTERS = 512_000;

    /** How many PDF stream objects to inflate before giving up on the rest. */
    private const MAX_STREAMS = 400;

    /**
     * How many letters make a decode believable.
     *
     * Not a quality bar — a floor under the word *"clean"*. Below this the
     * answer is `not_scanned`, which is what a photograph of a cheque gets and
     * what a stream that decoded to rubbish should get too.
     */
    private const MIN_LETTERS = 24;

    /**
     * Which parts of an OOXML zip hold the visible words.
     *
     * Prefixes, not filenames: a long `.docx` splits across `document2.xml`
     * and a workbook has one part per sheet.
     *
     * @var array<string, list<string>>
     */
    private const OFFICE_PARTS = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['word/document'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xl/sharedStrings', 'xl/worksheets/'],
    ];

    /**
     * The readable text of these bytes, or **null** when nothing can be read.
     *
     * Null and `''` mean different things and the caller depends on it: null
     * is *"we cannot see inside this"*, and an empty string is *"we looked and
     * there were no words"*.
     */
    public static function from(string $bytes, string $mimeType): ?string
    {
        $mimeType = mb_strtolower(trim($mimeType));

        if ($mimeType === 'application/pdf') {
            return self::meaningful(self::fromPdf($bytes));
        }

        if (array_key_exists($mimeType, self::OFFICE_PARTS)) {
            return self::meaningful(self::fromOffice($bytes, self::OFFICE_PARTS[$mimeType]));
        }

        if (str_starts_with($mimeType, 'text/')) {
            /*
             * Only if it really is text. A `.txt` holding arbitrary bytes is
             * not something to run patterns over, and `mb_check_encoding` is
             * the cheapest honest test.
             */
            return mb_check_encoding($bytes, 'UTF-8')
                ? mb_substr($bytes, 0, self::MAX_CHARACTERS)
                : null;
        }

        return null;
    }

    /**
     * Whether a file of this type can be looked inside at all.
     *
     * Asked separately from {@see self::from()} because the answer belongs on
     * the stored row: a document recorded as *not scanned* is a different
     * fact from one recorded as *scanned and clean*, and a screen that showed
     * them the same way would be making the guarantee Q6 warns about.
     */
    public static function supports(string $mimeType): bool
    {
        $mimeType = mb_strtolower(trim($mimeType));

        return $mimeType === 'application/pdf'
            || str_starts_with($mimeType, 'text/')
            || array_key_exists($mimeType, self::OFFICE_PARTS);
    }

    /**
     * Text that is actually text, or nothing.
     *
     * The second half of round 1's blocker, and the one that holds however the
     * extraction is later changed. `clean` means *read, and found nothing* —
     * so a handful of bytes that survived a bad decode must not earn it, or a
     * bank statement gets a badge saying it was checked.
     *
     * The bar is deliberately low: enough letters that a routing number and
     * the word "account" could both have been present and found. Below it,
     * **null** — which records `not_scanned`, the honest answer, and is what
     * a photograph of a cheque gets too.
     */
    private static function meaningful(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $letters = preg_match_all('/\p{L}/u', $text);

        return is_int($letters) && $letters >= self::MIN_LETTERS ? $text : null;
    }

    /**
     * A `.docx` or `.xlsx`, which are zips with the words in known parts.
     *
     * Round 1 of review, blocker 2: these were in the upload allowlist and
     * `supports()` said no, so a bank statement exported from Word stored as
     * `not_scanned` — while the help article told users the only thing that
     * slips past is a *photograph*. Either the type had to leave the allowlist
     * or the words had to be read; a real estate team exchanging disclosures
     * as Word files makes the second the only honest option.
     *
     * The XML is stripped rather than parsed. `<w:t>` runs carry the visible
     * text and a shared-strings table carries a spreadsheet's, but a cell
     * reference is not worth resolving for a scanner that only needs to know
     * whether a routing number is in there somewhere.
     *
     * @param  list<string>  $parts
     */
    private static function fromOffice(string $bytes, array $parts): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'office');

        if ($path === false) {
            return null;
        }

        try {
            if (file_put_contents($path, $bytes) === false) {
                return null;
            }

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return null;
            }

            $text = '';

            foreach ($parts as $part) {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);

                    /*
                     * A prefix match, because a `.docx` splits long documents
                     * across `word/document2.xml` and friends, and a workbook
                     * has one sheet part per sheet. Reading only the first
                     * would scan page one of a statement.
                     */
                    if (! is_string($name) || ! str_starts_with($name, $part)) {
                        continue;
                    }

                    $xml = $zip->getFromIndex($index, self::MAX_CHARACTERS);

                    if (is_string($xml)) {
                        // `<w:t>a</w:t><w:t>b</w:t>` must not become "ab".
                        $text .= ' '.strip_tags(str_replace('><', '> <', $xml));
                    }

                    if (mb_strlen($text) >= self::MAX_CHARACTERS) {
                        break 2;
                    }
                }
            }

            $zip->close();

            $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));

            return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARACTERS);
        } finally {
            @unlink($path);
        }
    }

    private static function fromPdf(string $bytes): ?string
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            return null;
        }

        /*
         * An encrypted PDF has an `/Encrypt` entry in its trailer and its
         * streams will not inflate to anything meaningful. Reported as
         * unreadable rather than decoded to noise, because noise scans clean.
         */
        if (str_contains($bytes, '/Encrypt')) {
            return null;
        }

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $matches) === false) {
            return null;
        }

        $text = '';
        $inflated = 0;

        foreach ($matches[1] as $stream) {
            if ($inflated >= self::MAX_STREAMS || mb_strlen($text) >= self::MAX_CHARACTERS) {
                break;
            }

            $inflated++;

            $decoded = self::inflate($stream);

            if ($decoded === null) {
                continue;
            }

            $text .= self::operators($decoded);
        }

        $text = trim($text);

        /*
         * Nothing came out. That is a scanned photograph in a PDF wrapper far
         * more often than it is a genuinely wordless document, so it is
         * **unreadable**, not empty — the caller must not record it as
         * scanned.
         */
        return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARACTERS);
    }

    /**
     * Decompress first, and only then consider the bytes as they came.
     *
     * **The order is the whole fix.** This used to decide a stream was
     * uncompressed by searching it for `Tj`/`TJ` — in the *compressed* bytes.
     * Deflate output is high-entropy, so those two-byte sequences turn up by
     * chance: round 1 of review measured 6.6% of ~1.5KB streams and **71% of
     * ~60KB streams**. A statement PDF then had its compressed bytes handed
     * to the operator regex, which matched a few bytes of noise, and a
     * non-empty result meant the row was recorded `clean`. A bank statement
     * nobody had read, labelled read and found nothing.
     *
     * Inflation is self-verifying in a way that guessing is not: zlib either
     * produces the original bytes or fails.
     */
    private static function inflate(string $stream): ?string
    {
        $stream = trim($stream, "\r\n");

        foreach (['gzuncompress', 'gzinflate'] as $filter) {
            $decoded = @$filter($stream);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        /*
         * Uncompressed streams are legal and common in generated PDFs, and
         * this is where they land — after inflation has failed rather than
         * before it was tried. `looksLikeContent()` is what keeps binary that
         * merely failed to inflate (an embedded font, a JPEG) out of the
         * operator regex.
         */
        return self::looksLikeContent($stream) ? $stream : null;
    }

    /**
     * Is this a PDF content stream rather than an inflate failure?
     *
     * A real content stream is text: operators, coordinates, string literals.
     * An embedded font or image that would not inflate is mostly bytes outside
     * the printable range, and feeding it to the operator regex is how noise
     * became a `clean` verdict.
     */
    private static function looksLikeContent(string $stream): bool
    {
        if ($stream === '' || ! str_contains($stream, '(')) {
            return false;
        }

        $sample = mb_substr($stream, 0, 2048, '8bit');
        $printable = preg_match_all('/[\x20-\x7E\r\n\t]/', $sample);

        return is_int($printable) && $printable >= (int) (mb_strlen($sample, '8bit') * 0.85);
    }

    /**
     * The text out of the text-showing operators.
     *
     * Three forms, and the first version handled only one:
     *
     * | form                        | written by                          |
     * |-----------------------------|-------------------------------------|
     * | `(text) Tj`                 | simple generators                   |
     * | `[(te) -20 (xt)] TJ`        | **Word, LibreOffice, most reporters** |
     * | `<48656c6c6f> Tj`           | subset/embedded fonts               |
     *
     * The kerned array form is what a word processor emits for ordinary
     * justified text, so missing it meant missing the documents most likely to
     * *be* a bank statement. Round 1 of review measured the consequence.
     *
     * Every literal in the stream is taken rather than only those followed by
     * an operator: inside a `TJ` array the literals are separated by kerning
     * numbers, and pairing each one with its operator would need a parser
     * rather than a pattern. Taking them all reads a little punctuation as
     * text, which costs nothing — this feeds a scanner, not a renderer.
     */
    private static function operators(string $content): string
    {
        $pieces = [];

        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/', $content, $literals) !== false) {
            foreach ($literals[1] as $literal) {
                $pieces[] = str_replace(
                    ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
                    ['(', ')', '\\', ' ', ' ', ' '],
                    $literal,
                );
            }
        }

        /*
         * Hex strings. A subset font writes `<0046004f>` for "FO", so the
         * decoded bytes are often UTF-16BE with a null between each character
         * — stripped here, because a routing number with nulls in it matches
         * nothing.
         */
        if (preg_match_all('/<([0-9A-Fa-f\s]{4,})>\s*(?:Tj|TJ)/', $content, $hex) !== false) {
            foreach ($hex[1] as $encoded) {
                $bytes = @hex2bin(preg_replace('/\s+/', '', $encoded) ?? '');

                if (is_string($bytes) && $bytes !== '') {
                    $pieces[] = str_replace("\0", '', $bytes);
                }
            }
        }

        return $pieces === [] ? '' : implode(' ', $pieces).' ';
    }
}
