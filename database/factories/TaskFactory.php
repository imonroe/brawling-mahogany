<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskSource;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_id' => Deal::factory(),
            'stage_id' => null,
            'title' => $this->faker->sentence(4),
            'is_required' => false,
            'source' => TaskSource::Manual,
            'sort_order' => 0,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }
}
