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
     * How many whitespace-separated tokens make a decode look like prose.
     *
     * With {@see self::MAX_WORD_LENGTH} this is the structural half of
     * {@see self::isConfident()}: armoured binary is one enormous token and a
     * font header is a few long identifiers, so neither clears both bars.
     */
    private const MIN_TOKENS = 6;

    /**
     * How many zip entries an OOXML file may cost before it stops being read.
     *
     * Enough for a long document split across parts and a workbook with a
     * sheet per month; far short of what a hand-built archive can hold.
     */
    private const MAX_OFFICE_PARTS = 64;

    /** Longer than any English word, and shorter than a base85 blob. */
    private const MAX_WORD_LENGTH = 40;

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
            return self::fromPdf($bytes);
        }

        if (array_key_exists($mimeType, self::OFFICE_PARTS)) {
            return self::fromOffice($bytes, self::OFFICE_PARTS[$mimeType]);
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
     * Is this decode good enough to call the document **checked**?
     *
     * ## It answers the label, never whether to scan
     *
     * Round 1 put this floor inside `from()`, so a document below it was
     * returned as `null` and never scanned at all. Round 2 measured what that
     * cost: a MICR-only cheque has **zero** letters, a one-line wire
     * instruction seven, an SSN card fourteen — the five shortest and most
     * dangerous documents in the threat model were the five the scanner
     * refused to look at. The `micr_line` check, documented as *"conclusive on
     * its own"*, was unreachable for any document whose only text is a MICR
     * line.
     *
     * So the order is now: extract whatever is there, **scan it however
     * little it is**, and ask this only to choose between `clean` and
     * `not_scanned`. A refusal never depends on it.
     */
    public static function isConfident(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        /*
         * **Truncated is not checked.** Extraction stops at
         * `MAX_CHARACTERS`, so a document longer than that was read in part —
         * and a routing number on the last page of a partial read is a routing
         * number nobody looked at. Reporting `clean` there is the same lie as
         * reporting it over an unreadable scan, arriving from the other end.
         *
         * Rare in practice: half a megabyte of extracted *text* is upwards of
         * a hundred pages. When it does happen, `not_scanned` is the honest
         * answer and the safe one.
         */
        if (mb_strlen($text) >= self::MAX_CHARACTERS) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $text);

        if (! is_int($letters) || $letters < self::MIN_LETTERS) {
            return false;
        }

        /*
         * **Letters alone are not language**, which round 2 of review proved
         * three ways on a page with no real text: an ASCII85 image stream
         * yielded 7,021 of them, an uncompressed Type1 font header 60, and an
         * XMP metadata packet enough to tip the count. All three would have
         * been labelled `clean`.
         *
         * What separates them from prose is **structure**. Armoured binary is
         * one enormous token; a font header is a handful of long identifiers.
         * Real text is many short words with spaces between them, so that is
         * what this asks for.
         */
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens) || count($tokens) < self::MIN_TOKENS) {
            return false;
        }

        $wordLike = 0;

        foreach ($tokens as $token) {
            // PREG_SPLIT_NO_EMPTY guarantees the lower bound.
            if (mb_strlen($token) <= self::MAX_WORD_LENGTH) {
                $wordLike++;
            }
        }

        // Most of what is there has to look like a word, not merely some of it.
        return $wordLike >= (int) ceil(count($tokens) * 0.6);
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
            $read = 0;

            foreach ($parts as $part) {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    /*
                     * A cap on **parts**, not only on characters. A 14MB
                     * `.docx` with thousands of matching entries cost three
                     * seconds of synchronous CPU on the request that uploaded
                     * it — an upload endpoint is a place somebody can choose
                     * the cost of, and `MAX_CHARACTERS` alone does not bound
                     * how many entries are opened to reach it.
                     */
                    if ($read >= self::MAX_OFFICE_PARTS) {
                        break 2;
                    }

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

                    $read++;

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

        /*
         * **A silent failure, and the worst kind.** `preg_match_all` with `.*?`
         * over a multi-megabyte stream exceeds PCRE's backtrack limit and
         * returns `false` — so a single large stream meant the whole document
         * was never scanned, and round 2 of review measured the threshold
         * between 900KB and 1.2MB. Every scanned contract in this product is
         * larger than that.
         *
         * Split on the delimiters instead, which does no backtracking at all
         * and has no size beyond which it stops working. A stream that reaches
         * this is bounded by `MAX_STREAMS` and `MAX_CHARACTERS` downstream, so
         * the cost of reading a large one is still capped.
         */
        /*
         * The lookbehind is load-bearing: `endstream` ends in `stream`, so a
         * naive split consumes the closing delimiter too and every chunk loses
         * the marker the loop below looks for. Nothing was found and the
         * document read as having no text layer at all.
         */
        $chunks = preg_split('/(?<!end)stream\r?\n/', $bytes);

        if (! is_array($chunks) || count($chunks) < 2) {
            return null;
        }

        $matches = [1 => []];

        foreach (array_slice($chunks, 1) as $chunk) {
            $end = mb_strpos($chunk, 'endstream', 0, '8bit');

            if ($end !== false) {
                $matches[1][] = mb_substr($chunk, 0, $end, '8bit');
            }
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
        /*
         * **Never `trim()`.** It was `trim($stream, "\r\n")`, and a zlib
         * stream ends in an Adler-32 checksum whose last byte is `0x0A` or
         * `0x0D` about 0.8% of the time — so the trim ate part of the
         * checksum, inflation failed, and the stream was silently dropped.
         * Round 3 of review measured the consequence: a 20-page PDF loses at
         * least one page 20% of the time and a 60-page one better than half,
         * and a bank statement whose statement page was dropped stored as
         * `clean`. Round 1's blocker arriving through a third door.
         *
         * The producer's own trailing newline is real and has to come off, so
         * the raw bytes are tried **first** and exactly one line ending is
         * removed only if that fails. Compressed data is not text and cannot
         * be tidied as though it were.
         */
        $candidates = [$stream];

        $withoutEnding = preg_replace('/\r\n$|\n$|\r$/', '', $stream);

        if (is_string($withoutEnding) && $withoutEnding !== $stream) {
            $candidates[] = $withoutEnding;
        }

        foreach ($candidates as $candidate) {
            foreach (['gzuncompress', 'gzinflate'] as $filter) {
                $decoded = @$filter($candidate);

                if (is_string($decoded) && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        $stream = (string) $withoutEnding;

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
        /*
         * A **text operator**, not merely a parenthesis. Round 2 of review got
         * an uncompressed Type1 font header through the printable-ratio test
         * with sixty letters, and a font header is structurally
         * indistinguishable from prose once it reaches the confidence check —
         * short space-separated tokens, plenty of letters.
         *
         * The distinguishing fact is one layer earlier: a content stream shows
         * text, and shows it with `Tj`, `TJ`, `'` or `"`. A font program, an
         * image, and an XMP packet do not. Testing for that here is cheaper
         * and far more certain than another heuristic downstream, which is
         * where the previous two attempts at this lived.
         */
        if ($stream === '' || ! str_contains($stream, '(')) {
            return false;
        }

        if (preg_match('/(?:\)|\])\s*(?:Tj|TJ|\x27|")/', $stream) !== 1) {
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
