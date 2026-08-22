<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GateTemplate;
use App\Models\StageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateTemplate>
 */
class GateTemplateFactory extends Factory
{
    protected $model = GateTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage_template_id' => StageTemplate::factory(),
            'gate_type' => 'manual_confirmation',
            'label' => $this->faker->sentence(3),
            'is_blocking' => true,
            'sort_order' => 0,
        ];
    }

    public function advisory(): static
    {
        return $this->state(fn (): array => ['is_blocking' => false]);
    }
}
