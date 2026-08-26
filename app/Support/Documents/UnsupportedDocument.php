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

    /**
     * A logo, which is a different refusal from a photograph.
     *
     * Same class because it is the same failure — bytes this product will not
     * store — and a second exception type would mean a second catch in every
     * upload controller. Different sentence because IA §10 wants the message
     * to say what to do next, and *"this gallery takes photographs"* is
     * useless advice to somebody on team settings.
     */
    public static function logoType(): self
    {
        return new self(
            'That file type is not accepted here. A logo can be a PNG, a JPEG or a GIF — '
            .'not an SVG, which a mail client will not draw.',
        );
    }

    public static function logoTooLarge(int $bytes): self
    {
        return new self(sprintf(
            'That logo is larger than %dKB. Export it at about 400 pixels wide and it will be well under.',
            (int) round($bytes / 1024),
        ));
    }

    public static function unwritable(): self
    {
        return new self('That upload could not be saved. Try again in a moment.');
    }
}
