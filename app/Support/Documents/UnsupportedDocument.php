<?php

declare(strict_types=1);

namespace App\Support\Documents;

use RuntimeException;

/**
 * A file this slice does not accept (PRD §4.6 · issue #63).
 *
 * The message never carries the filename. PRD §9 keeps PII out of logs, and a
 * filename is very often the most descriptive thing about a document —
 * `sellers-bank-statement.pdf` is exactly the string this product must not
 * write down while refusing to store the file it names.
 */
final class UnsupportedDocument extends RuntimeException
{
    public static function extension(string $extension): self
    {
        unset($extension);

        return new self(
            'That file type is not accepted here. This gallery takes photographs — JPEG, PNG, WebP or HEIC.',
        );
    }

    public static function tooLarge(int $bytes): self
    {
        return new self(sprintf(
            'That file is larger than %dMB. A photograph from a phone is usually well under it.',
            (int) round($bytes / 1024 / 1024),
        ));
    }

    public static function unwritable(): self
    {
        return new self('That upload could not be saved. Try again in a moment.');
    }
}
