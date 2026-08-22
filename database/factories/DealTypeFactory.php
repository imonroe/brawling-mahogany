<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DealSide;
use App\Models\DealType;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealType>
 */
class DealTypeFactory extends Factory
{
    use ForcesAttributes;

    protected $model = DealType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A team's own type by default. `system()` makes the shared kind,
            // which is the less common case in a test.
            'team_id' => null,
            'name' => $this->faker->unique()->words(2, true),
            'side' => DealSide::Sell,
            'sort_order' => 0,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['team_id' => null]);
    }

    public function buying(): static
    {
        return $this->state(fn (): array => ['side' => DealSide::Buy]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
