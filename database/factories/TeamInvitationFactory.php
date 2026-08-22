<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    use ForcesAttributes;

    protected $model = TeamInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'role_id' => Role::factory(),
            'token_hash' => TeamInvitation::hashToken(TeamInvitation::newToken()),
            'expires_at' => now()->addDays(TeamInvitation::LIFETIME_DAYS),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => ['accepted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }
}
