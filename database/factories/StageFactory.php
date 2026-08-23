<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StageState;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Workflow;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Stage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'workflow_id' => Workflow::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'sort_order' => 0,
            'state' => StageState::Pending,
            'is_milestone' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'state' => StageState::Active,
            'actual_start' => now(),
        ]);
    }

    public function milestone(string $label): static
    {
        return $this->state(fn (): array => [
            'is_milestone' => true,
            'milestone_label' => $label,
        ]);
    }
}
