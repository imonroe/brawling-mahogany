<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\RestrictedDocumentCategory;

/**
 * What a scan concluded about one upload (PRD §4.6 F6.7, §14.1 Q6).
 *
 * Three outcomes, not two, and the third is the one that matters:
 *
 * - **refused** — something conclusive was found, and the file is discarded.
 * - **clean** — the file was read and nothing was found.
 * - **unreadable** — the file could not be looked inside at all.
 *
 * Q6 asks whether a scan that misses things is worth having, *"because it
 * implies a guarantee that is not there"*. Collapsing unreadable into clean is
 * exactly how that guarantee gets implied: an image upload would be recorded
 * as having passed a check that never ran. So the third state exists, it is
 * stored on the row, and every screen that shows a scan result shows which of
 * the three it was.
 *
 * `signal` names the **kind** of thing that was found and never the thing
 * itself. PRD §9 and issue #100 item 3: the log records that a refusal
 * happened and its category, never the content and never a copy.
 */
final readonly class ScanOutcome
{
    private function __construct(
        public bool $readable,
        public ?RestrictedDocumentCategory $category,
        public ?string $signal,
    ) {}

    public static function refused(RestrictedDocumentCategory $category, string $signal): self
    {
        return new self(readable: true, category: $category, signal: $signal);
    }

    public static function clean(): self
    {
        return new self(readable: true, category: null, signal: null);
    }

    public static function unreadable(): self
    {
        return new self(readable: false, category: null, signal: null);
    }

    public function isRefused(): bool
    {
        return $this->category !== null;
    }

    /**
     * What goes on the stored row, for the one outcome that produces a row.
     *
     * A refusal never becomes a row — the file is discarded — so this only
     * ever answers for the two that survive.
     */
    public function state(): string
    {
        return $this->readable ? 'clean' : 'not_scanned';
    }
}
