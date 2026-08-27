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
     * How much compressed input to hand zlib per call.
     *
     * The bound on memory is on the **input**, because that is the side whose
     * size is known: deflate's worst case is a little over 1000:1, so 4KB in
     * cannot produce much more than 4MB out. Measured at 64KB the same loop
     * produced 23MB from a single call and fatalled a 32MB process.
     */
    private const INFLATE_CHUNK_BYTES = 4096;

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

    /**
     * Whether the last extraction stopped before the end of the document.
     *
     * Not a cache and not a latch — reset at the top of every
     * {@see self::from()} and read by the scan that called it.
     */
    private static bool $partial = false;

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
        /*
         * Reset per call. A per-process latch would be the hazard CLAUDE.md
         * records about statics in a web SAPI; this is a per-call detail of
         * one extraction, read by `wasPartial()` immediately afterwards, and
         * every entry point comes through here.
         */
        self::$partial = false;

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
    /**
     * Did the last {@see self::from()} read the whole document?
     *
     * Three things can stop it — the character ceiling, the stream budget and
     * the OOXML part cap — and until round 4 of review only the first was
     * visible to the confidence check. A partial read must never be `clean`,
     * whichever of the three ended it.
     */
    public static function wasPartial(): bool
    {
        return self::$partial;
    }

    public static function isConfident(?string $text): bool
    {
        if (self::$partial) {
            return false;
        }

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
                        // The third door on to the same lie: a 70-sheet
                        // workbook read in part is not a workbook that was
                        // checked.
                        self::$partial = true;

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
                        /*
                         * **The bound is on the part, so it can end a part in
                         * the middle.** A single `word/document.xml` over half
                         * a megabyte — a long disclosure packet, or any
                         * spreadsheet with a large shared-strings table — comes
                         * back cut, and the words after the cut were not read.
                         * The fourth door on to the same lie, and the one the
                         * PDF side had already been given two guards against.
                         */
                        if (strlen($xml) >= self::MAX_CHARACTERS) {
                            self::$partial = true;
                        }

                        // `<w:t>a</w:t><w:t>b</w:t>` must not become "ab".
                        $text .= ' '.strip_tags(str_replace('><', '> <', $xml));
                    }

                    if (mb_strlen($text) >= self::MAX_CHARACTERS) {
                        self::$partial = true;

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
         * ## Why this walks the file rather than splitting it
         *
         * `preg_split` on the delimiter fixed the backtracking, and round 5 of
         * review named what it cost instead: **a second copy of the whole
         * file, cut up**, and a third as each body is taken out of its chunk.
         * Measured on a realistic 15MB PDF — the size
         * `DocumentStorage::MAX_BYTES` permits — the split form peaks at
         * **52.3MB** where this one peaks at **24.3MB**, which is the file
         * itself and nothing above it. The overhead is a multiple of the
         * upload, so it grows with exactly the limit the image was about to
         * raise, and an out-of-memory is not something a `catch` downstream
         * can see.
         *
         * So the file is walked with `strpos` and each stream is inflated where
         * it is found. Only one stream is materialised at a time, and the text
         * accumulating beside it is capped at `MAX_CHARACTERS`.
         */
        $text = '';
        $inflated = 0;
        $offset = 0;
        $length = strlen($bytes);

        while (true) {
            if ($inflated >= self::MAX_STREAMS || mb_strlen($text) >= self::MAX_CHARACTERS) {
                /*
                 * **Stopped early, so the read is partial.** Recorded rather
                 * than inferred: round 4 of review found `isConfident()`
                 * guarding only the `MAX_CHARACTERS` door, so a 78KB PDF whose
                 * statement page sat at stream 460 of 500 came back `clean` —
                 * a document nobody finished reading, labelled checked.
                 */
                self::$partial = true;

                break;
            }

            $start = strpos($bytes, 'stream', $offset);

            if ($start === false) {
                break;
            }

            /*
             * `endstream` ends in `stream`. The split this replaced needed a
             * `(?<!end)` lookbehind for the same reason, and without it every
             * chunk lost the closing marker and the document read as having no
             * text layer at all.
             */
            if ($start >= 3 && substr($bytes, $start - 3, 3) === 'end') {
                $offset = $start + 6;

                continue;
            }

            $body = $start + 6;

            // The keyword is a delimiter only when a line ending follows it.
            if (substr($bytes, $body, 2) === "\r\n") {
                $body += 2;
            } elseif ($body < $length && ($bytes[$body] === "\n" || $bytes[$body] === "\r")) {
                $body += 1;
            } else {
                $offset = $start + 6;

                continue;
            }

            $end = strpos($bytes, 'endstream', $body);

            if ($end === false) {
                break;
            }

            $offset = $end + 9;

            $decoded = self::inflate(substr($bytes, $body, $end - $body));

            if ($decoded === null) {
                /*
                 * An image or a font costs nothing against the budget. It was
                 * counted before the attempt, so a PDF whose first four
                 * hundred streams are photographs spent the whole allowance on
                 * bytes with no text in them and never reached page two.
                 */
                continue;
            }

            $inflated++;

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
            foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
                $decoded = self::decompress($candidate, $encoding);

                if ($decoded !== null && $decoded !== '') {
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
     * Inflate a little at a time, and say so when the ceiling ends it.
     *
     * ## Why not `gzuncompress($bytes, $max)`
     *
     * Round 4 of review built a **1MB** PDF that fatals a 128MB process,
     * because deflate compresses repetition about a thousandfold; `@`
     * suppresses a warning, not an out-of-memory, so nothing downstream can
     * catch it. The bound was the fix, and round 5 found what the bound then
     * did: `gzuncompress` given a `max_length` returns **`false`** when the
     * output exceeds it — not a truncated string. So a legitimate stream over
     * half a megabyte was indistinguishable from an image, silently **dropped**
     * rather than truncated, and `$partial` was never set. The document came
     * back short and confident: `clean`, over a page nobody read. That is
     * round 1's blocker arriving through a fourth door.
     *
     * Inflating incrementally answers both. Memory is bounded by the *input*
     * chunk rather than the output — 4KB in cannot become more than about 4MB
     * out, where 64KB in was measured producing 23MB in a single call — and
     * hitting the ceiling is an observation this can report rather than a
     * failure indistinguishable from corruption.
     *
     * `ZLIB_STREAM_END` is what separates a stream that finished from one that
     * merely stopped, which is stricter than the two functions it replaces:
     * trailing bytes after the checksum end the read cleanly instead of
     * failing it, and a truncated stream is refused instead of decoding to a
     * prefix of noise.
     */
    private static function decompress(string $candidate, int $encoding): ?string
    {
        if ($candidate === '') {
            return null;
        }

        $context = @inflate_init($encoding);

        if ($context === false) {
            return null;
        }

        $out = '';
        $offset = 0;
        $length = strlen($candidate);

        while ($offset < $length) {
            $piece = @inflate_add($context, substr($candidate, $offset, self::INFLATE_CHUNK_BYTES), ZLIB_SYNC_FLUSH);

            if ($piece === false) {
                return null;
            }

            $out .= $piece;
            $offset += self::INFLATE_CHUNK_BYTES;

            if (strlen($out) >= self::MAX_CHARACTERS) {
                /*
                 * Truncated, not dropped — and **recorded**, which is the
                 * whole point of doing it this way. A stream this long is one
                 * the caller was going to cut anyway; what it must not do is
                 * call the result checked.
                 */
                self::$partial = true;

                return substr($out, 0, self::MAX_CHARACTERS);
            }

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                return $out;
            }
        }

        /*
         * All the input consumed and no `ZLIB_STREAM_END` on the way past: the
         * stream is truncated or was never deflate at all, so what came out is
         * a prefix of noise rather than a short document. Refused, which sends
         * it on to `looksLikeContent()` to be judged as raw bytes.
         */
        return null;
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

        /*
         * **Possessive**, and it is not a micro-optimisation.
         *
         * An unterminated `(` — which is exactly what truncating a stream at
         * `MAX_CHARACTERS` produces — makes the greedy form run to the end of
         * the subject looking for a `)`, and PCRE gives up: `preg_match_all`
         * returns **`false`** with *"JIT stack limit exhausted"*, measured at
         * ~500KB. The `!== false` guard below then swallowed it and the whole
         * stream yielded nothing, so the truncated read this class had just
         * gone to some trouble to preserve was thrown away one method later.
         *
         * `*+` cannot backtrack, so the dangling literal fails at its own start
         * position instead of dragging the rest of the match down with it — and
         * the alternation was already deterministic (`[^\\()]` excludes the
         * backslash), so nothing correct is given up for it.
         */
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*+)\)/', $content, $literals) !== false) {
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
