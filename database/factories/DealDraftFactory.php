<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DealDraftStep;
use App\Models\DealDraft;
use App\Models\Person;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealDraft>
 */
class DealDraftFactory extends Factory
{
    use ForcesAttributes;

    protected $model = DealDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_person_id' => Person::factory(),
            'step' => DealDraftStep::Type,
            // An empty payload is the honest default: a draft starts with
            // nothing said.
            'payload' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function at(DealDraftStep $step, array $payload = []): static
    {
        return $this->state(fn (): array => ['step' => $step, 'payload' => $payload]);
    }
}
