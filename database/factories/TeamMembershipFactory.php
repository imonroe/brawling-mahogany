<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    protected $model = TeamMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'person_id' => Person::factory(),
            'status' => PersonLifecycleState::Active,
            'is_vendor' => false,
            'joined_at' => now(),
        ];
    }

    public function lead(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => PersonLifecycleState::Lead]);
    }

    public function pastClient(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => PersonLifecycleState::PastClient]);
    }

    public function vendor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_vendor' => true,
            'vendor_specialties' => ['staging'],
            'vendor_service_area' => 'Denver metro',
            'vendor_rating' => 4,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }
}
