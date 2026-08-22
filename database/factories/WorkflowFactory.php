<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\Team;
use App\Models\Workflow;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Workflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_id' => Deal::factory(),
            'name' => $this->faker->words(3, true),
            'state' => WorkflowState::Active,
            // Not null: the column is NOT NULL because a workflow without its
            // definition is a workflow nobody can explain (F4.5).
            'template_snapshot' => ['stages' => []],
        ];
    }
}
