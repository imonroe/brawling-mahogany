<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OfferDirection;
use App\Enums\OfferStatus;
use App\Models\Deal;
use App\Models\Offer;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Offer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'direction' => OfferDirection::Received,
            'status' => OfferStatus::Submitted,
            // Integer cents (ADR 0001): $485,000.
            'amount' => 48_500_000,
            'earnest_money' => 500_000,
            'submitted_on' => now()->toDateString(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => OfferStatus::Accepted]);
    }

    public function made(): static
    {
        return $this->state(fn (array $attributes): array => ['direction' => OfferDirection::Made]);
    }
}
