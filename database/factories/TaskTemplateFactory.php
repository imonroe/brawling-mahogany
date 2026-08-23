<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    protected $model = TaskTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage_template_id' => StageTemplate::factory(),
            'title' => $this->faker->sentence(4),
            'owner_role' => null,
            'due_offset_days' => null,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }
}
