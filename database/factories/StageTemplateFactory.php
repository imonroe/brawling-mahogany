<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StageTemplate;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageTemplate>
 */
class StageTemplateFactory extends Factory
{
    protected $model = StageTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_template_id' => WorkflowTemplate::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'sort_order' => 0,
            'expected_duration_days' => 5,
            'owner_role' => null,
            'is_milestone' => false,
        ];
    }

    public function milestone(string $label): static
    {
        return $this->state(fn (): array => [
            'is_milestone' => true,
            'client_facing_label' => $label,
        ]);
    }
}
