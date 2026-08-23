<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExternalLink;
use App\Models\Property;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ExternalLink>
 */
class ExternalLinkFactory extends Factory
{
    use ForcesAttributes;

    protected $model = ExternalLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'linkable_type' => Property::class,
            'linkable_id' => Property::factory(),
            'label' => $this->faker->randomElement(['Zillow', 'County assessor', 'Virtual tour']),
            // Unique, because the table refuses the same URL twice on one
            // record and a factory that made ten links should make ten.
            'url' => 'https://example.test/'.$this->faker->unique()->slug(),
            'sort_order' => 0,
        ];
    }

    /** Point this link at a specific record, in that record's team. */
    public function attachedTo(Model $linkable): static
    {
        return $this->state(fn (): array => [
            'linkable_type' => $linkable->getMorphClass(),
            'linkable_id' => $linkable->getKey(),
            'team_id' => $linkable->getAttribute('team_id'),
        ]);
    }
}
