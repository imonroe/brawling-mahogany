<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeyDate>
 */
class KeyDateFactory extends Factory
{
    use ForcesAttributes;

    protected $model = KeyDate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_id' => Deal::factory(),
            'name' => $this->faker->randomElement([
                'Mutual acceptance',
                'Inspection objection',
                'Appraisal',
                'Loan commitment',
                'Closing',
            ]),
            'date' => now()->addDays($this->faker->numberBetween(1, 45))->toDateString(),
            'is_critical' => false,
            'is_derived' => false,
            'source' => KeyDateSource::Manual,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (): array => ['is_critical' => true]);
    }

    /**
     * Derived from another date.
     *
     * Sets the value too, because the migration's CHECK refuses a half-built
     * derivation and because a factory that produced a derived row whose date
     * disagreed with its own anchor would be a fixture that tests nothing —
     * every assertion about the cascade would be measuring the fixture.
     */
    public function derivedFrom(KeyDate $anchor, int $days, OffsetBasis $basis = OffsetBasis::Calendar): static
    {
        return $this->state(fn (): array => [
            'anchor_key_date_id' => $anchor->getKey(),
            'offset_days' => $days,
            'offset_basis' => $basis,
            'is_derived' => true,
            'date' => $basis->apply($anchor->date, $days)->toDateString(),
        ]);
    }

    /**
     * Slice 5's *extracted-pending* (#116): read off a contract, agreed to by
     * nobody. Not counted as a deadline anywhere until it is confirmed.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'source' => KeyDateSource::Extracted,
            'confirmed_at' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'source' => KeyDateSource::Extracted,
            'confirmed_at' => now(),
        ]);
    }
}
