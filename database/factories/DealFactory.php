<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DealState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Deal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_type_id' => DealType::factory(),
            'name' => null,
            'generated_name' => $this->faker->streetAddress(),
            'state' => DealState::Active,
            'opened_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'state' => DealState::Closed,
            'closed_at' => now(),
        ]);
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
