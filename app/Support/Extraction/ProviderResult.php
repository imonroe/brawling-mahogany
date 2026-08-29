<?php

declare(strict_types=1);

namespace App\Support\Extraction;

/**
 * What one provider call cost and what it said.
 *
 * PRD §8.4: *"Cost and latency per extraction are recorded, because at scale
 * this is the one line item that grows with usage."* Both are on this object
 * rather than measured by the caller, because only the provider knows its own
 * pricing and only the provider can time the call it made rather than the
 * queue wait in front of it.
 *
 * `$raw` is the response body as the provider returned it — F10.4 asks for the
 * raw output retained, and #118's *"what is the model getting wrong"* question
 * is unanswerable from the parsed proposals alone.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $modelVersion,
        public array $raw,
        public int $inputTokens,
        public int $outputTokens,
        public int $costMicros,
        public int $latencyMs,
    ) {}
}
