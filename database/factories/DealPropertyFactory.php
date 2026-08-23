<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealProperty>
 */
class DealPropertyFactory extends Factory
{
    use ForcesAttributes;

    protected $model = DealProperty::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'property_id' => Property::factory(),
            'is_subject' => false,
        ];
    }
}
