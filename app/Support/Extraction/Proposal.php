<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ExtractedFieldType;

/**
 * One thing the model said, before it becomes a row.
 *
 * The seam between "what the provider returned" and "what `extracted_fields`
 * holds". It exists so that `ReadProposals` can be a pure function over a
 * response body — testable without a database, without a team, and without a
 * provider — and so that a second provider returning a different JSON shape has
 * exactly one place to be normalised into.
 */
final readonly class Proposal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ExtractedFieldType $type,
        public string $label,
        public string $value,
        public ?float $confidence,
        public ?int $sourcePage,
        public ?string $sourceSnippet,
        public array $payload = [],
    ) {}
}
