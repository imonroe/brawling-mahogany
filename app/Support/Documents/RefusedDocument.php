<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\RestrictedDocumentCategory;
use RuntimeException;

/**
 * An upload this product will not keep (PRD §4.6 F6.2, F6.7 · issues #99, #100).
 *
 * Distinct from {@see UnsupportedDocument}, which is about a file *type* this
 * slice does not handle. This one is about **content**, and the difference is
 * the whole of S53: an unsupported file is a mistake somebody corrects by
 * exporting differently, and a refused one is a policy they need to understand
 * and a place they need to be sent instead.
 *
 * PRD §1.1 puts this product alongside the team's e-signature platform rather
 * than in front of it, and the refusal screen is where that positioning stops
 * being a paragraph in a document and becomes a thing a person is told at the
 * moment it affects them.
 *
 * ## It never carries the content
 *
 * `signal` is the kind of thing that was found. Not the matched string, not an
 * offset, not a copy of the file. #100 item 3 and PRD §9 — the log records
 * that a refusal happened and its category, and nothing else — and an
 * exception message is a string that ends up in a log by default.
 */
final class RefusedDocument extends RuntimeException
{
    private function __construct(
        public readonly RestrictedDocumentCategory $category,
        public readonly string $signal,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The scan found something conclusive.
     */
    public static function detected(RestrictedDocumentCategory $category, string $signal): self
    {
        return new self(
            $category,
            $signal,
            $category->refusalReason(),
        );
    }

    /**
     * What to do instead, which is the half that makes this a policy rather
     * than an obstruction.
     *
     * Screen Inventory S53 lists *"what to do instead"* as a key state, and
     * issue #99 says why: *"that is the part that makes this acceptable rather
     * than infuriating."*
     */
    public function alternative(): string
    {
        return match ($this->category) {
            RestrictedDocumentCategory::ExecutedContract => 'Keep it in your e-signature system, which is the system of record for executed contracts.',
            RestrictedDocumentCategory::EarnestMoneyInstrument,
            RestrictedDocumentCategory::LendingPacket,
            RestrictedDocumentCategory::BankStatement => 'Keep it wherever your transaction paperwork lives — your e-signature platform or your brokerage’s own system, both of which are built to hold it.',
            RestrictedDocumentCategory::GovernmentId => 'Identity documents belong with whoever asked for them, not on a deal record.',
        };
    }
}
