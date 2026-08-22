<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    protected $model = WorkflowTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => null,
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'version' => 1,
            'is_active' => true,
        ];
    }
}
