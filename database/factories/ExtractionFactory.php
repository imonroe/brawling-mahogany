<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Enums\ExtractionKind;
use App\Enums\ExtractionState;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * One attempt at reading a document (#115).
 *
 * ## `ForcesAttributes`, because nothing here is fillable
 *
 * `Extraction` is `#[Fillable([])]` by design — every column is a fact about a
 * provider call or about the pipeline's own progress, and none of them is a
 * thing a request body supplies. Laravel's factories build through the
 * constructor, which respects that list and **silently drops** what it cannot
 * fill, so without the trait every row would come out with null foreign keys
 * and a confusing constraint violation.
 *
 * ## The deal and the document are resolved from the team, not beside it
 *
 * `teamScopedForeign()` makes both foreign keys composite over
 * `(team_id, id)`, so a definition that named three independent
 * `Team::factory()`s would produce a row Postgres refuses. Testing §5 asks that
 * *"every factory produces a valid record with no arguments"*, and for a table
 * with two composite keys out of it that means the parents have to be built
 * **inside the team the row is going into**. The closures below read
 * `$attributes['team_id']`, which Laravel has already expanded by the time they
 * run, so naming the team is enough and the deal and the document follow it.
 *
 * Like every factory in this directory, that still assumes a resolved
 * `TeamContext` — `BelongsToTeam` refuses a write aimed at another team, and
 * `DealTypeFactory` fills its own tenant from the context rather than carrying
 * one. A test says which team; the factory works out the rest.
 *
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Extraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_id' => fn (array $attributes): string => Deal::factory()
                ->create(['team_id' => $attributes['team_id']])
                ->getKey(),
            /*
             * `text/plain`, so a factory-built document is one `ReadableText`
             * can actually read. A default this pipeline cannot open would make
             * every test that reached the worker assert on the *"no words in
             * this file"* refusal rather than on what it meant to.
             */
            'document_id' => fn (array $attributes): string => Document::factory()
                ->create([
                    'team_id' => $attributes['team_id'],
                    'documentable_type' => (new Deal)->getMorphClass(),
                    'documentable_id' => $attributes['deal_id'],
                    'category' => DocumentCategory::Other,
                    'original_name' => 'contract.txt',
                    'mime_type' => 'text/plain',
                    'path' => 'fixtures/'.Str::ulid()->toString().'.txt',
                ])
                ->getKey(),
            'kind' => ExtractionKind::Contract,
            'state' => ExtractionState::Queued,
            'cost_micros' => 0,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'state' => ExtractionState::Queued,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /** Claimed by a worker and not yet finished — S65's *Reading*. */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'state' => ExtractionState::Processing,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    /**
     * A call that answered, with the provenance F10.4 requires beside it.
     *
     * Every one of these columns together, rather than as separate arguments:
     * a `complete` row missing its model or its cost is a row the product
     * cannot produce, and a fixture that could produce one would let a screen
     * assert against a shape that never reaches it.
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ExtractionState::Complete,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'model_version' => 'claude-sonnet-5-20260101',
            /*
             * Taken from the kind rather than pinned, because the two prompts
             * are versioned separately and a row claiming the contract prompt
             * over an inspection's proposals is a provenance record that lies.
             * Order matters, and only one way round: `->inspection()->complete()`.
             */
            'prompt_version' => $this->promptVersionFor($attributes['kind'] ?? null),
            'input_tokens' => 9_000,
            'output_tokens' => 1_200,
            'cost_micros' => 45_000,
            'latency_ms' => 4_100,
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
            'error' => null,
            'error_code' => null,
        ]);
    }

    /**
     * #115: *"failure is a state, not an exception the user meets as a 500."*
     *
     * So a failed row carries the sentence somebody reads on S65 **and** the
     * enumerated code an operator greps, because the two have different
     * readers and a fixture with only one of them cannot hold either promise.
     */
    public function failed(
        string $message = 'The reading service answered in a form this app could not use.',
        string $code = 'provider_response_unreadable',
    ): static {
        return $this->state(fn (): array => [
            'state' => ExtractionState::Failed,
            'error' => $message,
            'error_code' => $code,
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
        ]);
    }

    /**
     * Stopped by a spend cap (#113) — a refusal, not a breakage.
     *
     * `cost_micros` stays zero: nothing was called, so counting this row
     * against the ceiling would make the ceiling lower every time it fired.
     */
    public function blocked(): static
    {
        return $this->state(fn (): array => [
            'state' => ExtractionState::Blocked,
            'error' => 'This team has reached its monthly limit for reading documents.',
            'error_code' => 'team_spend_cap_reached',
            'cost_micros' => 0,
            'completed_at' => now(),
        ]);
    }

    public function contract(): static
    {
        return $this->state(fn (): array => ['kind' => ExtractionKind::Contract]);
    }

    public function inspection(): static
    {
        return $this->state(fn (): array => ['kind' => ExtractionKind::Inspection]);
    }

    /**
     * A row that spent money, for the ledger's sake.
     *
     * Millionths of a dollar, the unit `extractions.cost_micros` is in — named
     * `$micros` in the signature so a caller cannot pass cents by habit and
     * quietly under-count a month's spend by four orders of magnitude.
     */
    public function costing(int $micros): static
    {
        return $this->state(fn (): array => ['cost_micros' => $micros]);
    }

    /**
     * The prompt whose words produced this row, whichever way the kind arrived.
     *
     * A state may be handed an enum (from the definition, or from
     * {@see self::inspection()}) or the raw string a test wrote by hand, and a
     * comparison that only knew one of them would silently fall through to the
     * contract prompt for the other.
     */
    private function promptVersionFor(mixed $kind): string
    {
        $resolved = $kind instanceof ExtractionKind
            ? $kind
            : (is_string($kind) ? ExtractionKind::tryFrom($kind) : null);

        return $resolved === ExtractionKind::Inspection
            ? 'inspection-2026-08-28'
            : 'contract-2026-08-28';
    }
}
