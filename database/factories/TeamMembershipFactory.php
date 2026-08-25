<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    use ForcesAttributes;

    protected $model = TeamMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'person_id' => Person::factory(),
            // What this team knows about them (#140).
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => PersonLifecycleState::Active,
            'is_vendor' => false,
            'joined_at' => now(),
        ];
    }

    /**
     * A colleague: no lifecycle value at all (#162).
     *
     * IA §8's states describe a contact, so a membership that carries team
     * access holds null — which is what `AcceptInvitation` writes. Attach the
     * roles separately; this is the lifecycle half.
     */
    public function colleague(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => null]);
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
