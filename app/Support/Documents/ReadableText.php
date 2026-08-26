<?php

declare(strict_types=1);

namespace App\Support\Documents;

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

        return $mimeType === 'application/pdf' || str_starts_with($mimeType, 'text/');
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

    private static function inflate(string $stream): ?string
    {
        $stream = trim($stream, "\r\n");

        // Uncompressed streams are legal and common in generated PDFs.
        if (str_contains($stream, 'Tj') || str_contains($stream, 'TJ')) {
            return $stream;
        }

        foreach (['gzuncompress', 'gzinflate'] as $filter) {
            $decoded = @$filter($stream);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * The string literals out of the text-showing operators.
     */
    private static function operators(string $content): string
    {
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|TJ|\')/', $content, $matches) === false) {
            return '';
        }

        $pieces = array_map(
            static fn (string $literal): string => str_replace(
                ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
                ['(', ')', '\\', ' ', ' ', ' '],
                $literal,
            ),
            $matches[1],
        );

        return $pieces === [] ? '' : implode(' ', $pieces).' ';
    }
}
