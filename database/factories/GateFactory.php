<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gate;
use App\Models\Stage;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gate>
 */
class GateFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Gate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'stage_id' => Stage::factory(),
            'gate_type' => 'manual_confirmation',
            'label' => $this->faker->sentence(3),
            'is_blocking' => true,
            'is_met' => false,
            'overridden' => false,
            'sort_order' => 0,
        ];
    }

    public function met(): static
    {
        return $this->state(fn (): array => ['is_met' => true, 'met_at' => now()]);
    }

    public function advisory(): static
    {
        return $this->state(fn (): array => ['is_blocking' => false]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function ofType(string $type, array $config = []): static
    {
        return $this->state(fn (): array => ['gate_type' => $type, 'config' => $config]);
    }
}
