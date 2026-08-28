<?php

declare(strict_types=1);

namespace App\Support\Extraction\Providers;

use App\Support\Extraction\Contracts\ExtractionPrompt;
use App\Support\Extraction\Contracts\ExtractionProvider;
use App\Support\Extraction\ProviderFailed;
use App\Support\Extraction\ProviderResult;
use App\Support\Extraction\Redaction\RedactedDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Anthropic Messages API, behind F10.6's interface.
 *
 * Everything provider-shaped is in this file and `config/extraction.php`.
 * Nothing downstream — the pipeline, the review screens, the key dates cascade
 * — imports this class or knows the name of the company that runs it, which is
 * what F10.6 is asking for.
 *
 * ## What the cost is computed from, and why it is not taken on trust
 *
 * The response carries a token usage block, and the price per token is
 * configuration. Multiplying the two here rather than reading a cost the API
 * quotes is deliberate: PRD §14.3 asks for cost tracked *from day one*, and a
 * provider that stops returning a field, or returns it in a different unit,
 * would silently write noughts into the one column the pricing model depends
 * on. A missing usage block is a failure, not a free extraction.
 */
final class AnthropicProvider implements ExtractionProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) config('extraction.anthropic.model');
    }

    public function isConfigured(): bool
    {
        return is_string(config('extraction.anthropic.api_key'))
            && config('extraction.anthropic.api_key') !== '';
    }

    public function extract(RedactedDocument $document, ExtractionPrompt $prompt): ProviderResult
    {
        if (! $this->isConfigured()) {
            throw ProviderFailed::notConfigured();
        }

        $model = $this->model();
        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('extraction.anthropic.api_key'),
                'anthropic-version' => (string) config('extraction.anthropic.api_version'),
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('extraction.anthropic.timeout'))
                /*
                 * No `retry()`. Retrying here would charge for each attempt
                 * inside one job and hide the count from `extractions`, and the
                 * queue already has four attempts with the row's own record of
                 * each. One call per row is what makes the cost column mean
                 * what §14.3 needs it to mean.
                 */
                ->post(rtrim((string) config('extraction.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => $model,
                    'max_tokens' => (int) config('extraction.anthropic.max_tokens'),
                    'system' => $prompt->system(),
                    /*
                     * Zero, because this is a reading task with a right answer.
                     * A model that phrases the same deadline differently on two
                     * runs makes #118's regression harness unable to tell a
                     * prompt change from noise.
                     */
                    'temperature' => 0,
                    'messages' => [[
                        'role' => 'user',
                        'content' => $prompt->instructions($document->text),
                    ]],
                ]);
        } catch (ConnectionException) {
            throw ProviderFailed::unavailable();
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->serverError()) {
            throw ProviderFailed::unavailable();
        }

        if ($response->failed()) {
            throw ProviderFailed::refused($response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : null;

        if ($usage === null) {
            /*
             * A 200 with no usage block. The words might be perfect and the
             * cost would be recorded as zero, which is worse than failing:
             * every later cap check and every per-deal average would be
             * computed from a number known to be wrong, and nothing would say
             * so. §14.3's whole argument is that this column is the one that
             * decides whether the product is profitable.
             */
            throw ProviderFailed::unreadableResponse();
        }

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        return new ProviderResult(
            provider: $this->name(),
            model: $model,
            /*
             * The model id the API says it served, which is not always the one
             * asked for — an alias resolves to a dated build. #118 re-runs the
             * corpus on *every model version change*, and it can only notice
             * one it was told about.
             */
            modelVersion: is_string($body['model'] ?? null) ? $body['model'] : $model,
            raw: $body,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costMicros: $this->costMicros($model, $inputTokens, $outputTokens),
            latencyMs: $latencyMs,
        );
    }

    /**
     * Tokens times the configured rate, rounded up.
     *
     * Up, not to nearest: the number this feeds is a spend cap, and a rounding
     * rule that can report less than was spent is a cap that can be walked
     * past a fraction at a time.
     *
     * An unpriced model costs **nothing recorded**, and that is a deliberate
     * and uncomfortable choice. The alternative — refusing to run a model with
     * no price — would make trying a new model a config change plus a code
     * change, which is exactly the friction #118's harness exists to remove.
     * So it runs, and `PerformExtraction` logs `pricing_missing` so the gap is
     * visible rather than silently discounted.
     */
    private function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        /** @var array<string, array{input: int, output: int}> $pricing */
        $pricing = config('extraction.pricing', []);

        $rates = $pricing[$model] ?? null;

        if ($rates === null) {
            return 0;
        }

        return (int) ceil($inputTokens * $rates['input'] / 1_000_000)
            + (int) ceil($outputTokens * $rates['output'] / 1_000_000);
    }
}
