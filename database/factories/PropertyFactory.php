<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'street' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state_code' => $this->faker->randomElement(['CO', 'CA', 'TX', 'NY']),
            'postal_code' => $this->faker->postcode(),
            'type' => PropertyType::SingleFamily,
            'status' => PropertyStatus::PreListing,
            'beds' => $this->faker->numberBetween(1, 5),
            'baths' => $this->faker->randomElement(['1.0', '1.5', '2.0', '3.5']),
            'sqft' => $this->faker->numberBetween(600, 4200),
            'year_built' => $this->faker->numberBetween(1900, 2025),
        ];
    }

    public function withStatus(PropertyStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /** A property somebody created from a parcel number, with no address yet. */
    public function withoutAddress(): static
    {
        return $this->state(fn (): array => [
            'street' => null,
            'city' => null,
            'state_code' => null,
            'postal_code' => null,
            'parcel_number' => $this->faker->numerify('##-###-##-###'),
        ]);
    }
}
