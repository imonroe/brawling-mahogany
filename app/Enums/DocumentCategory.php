<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * PRD §6.3 document category — the categories a person may choose.
 *
 * The refusal list is deliberately a different type. See
 * {@see RestrictedDocumentCategory}: those are not categories a user can pick,
 * and modelling them here would eventually put "Bank statement" in an upload
 * dropdown.
 */
enum DocumentCategory: string implements HasLabel
{
    use ProvidesOptions;

    case InspectionReport = 'inspection_report';
    case Disclosure = 'disclosure';
    case Marketing = 'marketing';
    case Photo = 'photo';
    case Receipt = 'receipt';
    case Correspondence = 'correspondence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InspectionReport => 'Inspection report',
            self::Disclosure => 'Disclosure',
            self::Marketing => 'Marketing',
            self::Photo => 'Photo',
            self::Receipt => 'Receipt',
            self::Correspondence => 'Correspondence',
            self::Other => 'Other',
        };
    }
}
