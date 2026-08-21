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
 * account number, a government ID carries everything needed to impersonate
 * somebody, and this product is explicitly not the system of record for
 * executed contracts — that is the team's e-signature platform (PRD §10).
 */
enum RestrictedDocumentCategory: string implements HasLabel
{
    use ProvidesOptions;

    case ExecutedContract = 'executed_contract';
    case EarnestMoneyInstrument = 'earnest_money_instrument';
    case LendingPacket = 'lending_packet';
    case BankStatement = 'bank_statement';
    case GovernmentId = 'government_id';

    public function label(): string
    {
        return match ($this) {
            self::ExecutedContract => 'Executed contract',
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
            self::ExecutedContract => 'Executed contracts belong in your e-signature system, which is the system of record for them.',
            self::EarnestMoneyInstrument => 'Cheque images carry routing and account numbers, so they are never stored here.',
            self::LendingPacket => 'Lending packets carry financial identifiers, so they are never stored here.',
            self::BankStatement => 'Bank statements carry account numbers, so they are never stored here.',
            self::GovernmentId => 'Identity documents are never stored here.',
        };
    }
}
