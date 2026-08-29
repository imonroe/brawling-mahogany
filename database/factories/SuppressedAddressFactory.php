<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\SuppressedAddress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SuppressedAddress>
 */
class SuppressedAddressFactory extends Factory
{
    protected $model = SuppressedAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * Through the model's own normaliser, because a factory that
             * writes a form the lookup cannot find is a factory that makes
             * every suppression test pass against a broken lookup.
             */
            'email' => SuppressedAddress::normalise($this->faker->unique()->safeEmail()),
            'reason' => SuppressionReason::HardBounce,
            'detail' => 'smtp; 550 5.1.1 user unknown',
            'discovered_by_team_id' => null,
            'suppressed_at' => Carbon::now(),
        ];
    }

    public function complaint(): self
    {
        return $this->state(fn (): array => [
            'reason' => SuppressionReason::Complaint,
            'detail' => 'abuse report from the receiving provider',
        ]);
    }
}
