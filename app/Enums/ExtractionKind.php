<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What kind of document an extraction is reading — PRD §6.2.
 *
 * The kind decides three things and nothing else: which prompt runs, what
 * `field_type`s the proposals may carry, and which of the two review screens
 * the same route renders. Screen Inventory gives S66 and S67 **the same URL**
 * discriminated by this column, which is why it is a stored fact about the
 * attempt rather than something a controller works out from the document's
 * category.
 */
enum ExtractionKind: string implements HasLabel
{
    use ProvidesOptions;

    case Contract = 'contract';
    case Inspection = 'inspection';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::Inspection => 'Inspection report',
        };
    }

    /**
     * What this kind is allowed to propose.
     *
     * A contract proposes dates and provisions; an inspection report proposes
     * tasks. The list is here rather than in the prompt because it is the
     * thing the *response reader* validates against — a model that returns a
     * task for a contract has misunderstood the request, and silently
     * accepting it would put a row on a screen that has no control for it.
     *
     * @return list<ExtractedFieldType>
     */
    public function proposes(): array
    {
        return match ($this) {
            self::Contract => [ExtractedFieldType::KeyDate, ExtractedFieldType::Provision],
            self::Inspection => [ExtractedFieldType::Task],
        };
    }
}
