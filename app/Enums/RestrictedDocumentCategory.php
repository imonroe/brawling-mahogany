<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * The refusal list — PRD §4.6 F6.2 and §6.3.
 *
 * These are **not** document categories. A document detected as one of these
 * is refused outright: it is never written to permanent storage, and the
 * refusal is logged without the file (PRD §8.4). They live in their own type
 * precisely so no upload form can ever offer them as an option.
 *
 * The reason is concrete: an earnest money instrument carries a routing and
 * account number, and a government ID carries everything needed to
 * impersonate somebody. Every case here is a **financial or identity**
 * document, and that is now the whole of the list.
 *
 * ## Why the executed contract left this list (#209)
 *
 * It was here on a different argument — not that the document is dangerous,
 * but that this product is not its system of record (PRD §10). That argument
 * survives and is unchanged: the e-signature platform still holds the
 * authoritative signed artefact, and nothing here claims otherwise.
 *
 * What did not survive is refusing it *before storage*, because F10.1 — the
 * feature that reaches parity with the competitor — exists to read exactly
 * that document, and PRD §5.3 walks Heather through uploading it. A refusal
 * that discards the input to the slice is not a compliance control; it is a
 * contradiction between two sections of the same document.
 *
 * The distinction that keeps §10 true: this product holds a **copy read for
 * its dates**, not the record of the transaction. Say that in the terms and
 * in the manual rather than enforcing it by making the feature impossible.
 */
enum RestrictedDocumentCategory: string implements HasLabel
{
    use ProvidesOptions;

    case EarnestMoneyInstrument = 'earnest_money_instrument';
    case LendingPacket = 'lending_packet';
    case BankStatement = 'bank_statement';
    case GovernmentId = 'government_id';

    public function label(): string
    {
        return match ($this) {
            self::EarnestMoneyInstrument => 'Earnest money instrument',
            self::LendingPacket => 'Lending packet',
            self::BankStatement => 'Bank statement',
            self::GovernmentId => 'Government ID',
        };
    }

    /**
     * What the person is told, and where the document belongs instead.
     *
     * A refusal that only prohibits reads as a bug; a refusal that names the
     * alternative reads as a policy (Design System §7.4, PII warning).
     */
    public function refusalReason(): string
    {
        return match ($this) {
            self::EarnestMoneyInstrument => 'Cheque images carry routing and account numbers, so they are never stored here.',
            self::LendingPacket => 'Lending packets carry financial identifiers, so they are never stored here.',
            self::BankStatement => 'Bank statements carry account numbers, so they are never stored here.',
            self::GovernmentId => 'Identity documents are never stored here.',
        };
    }
}
