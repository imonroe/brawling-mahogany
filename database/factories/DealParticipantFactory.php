<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealParticipant>
 */
class DealParticipantFactory extends Factory
{
    protected $model = DealParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'team_membership_id' => TeamMembership::factory(),
            'participant_role' => ParticipantRole::Seller,
            'is_primary' => false,
        ];
    }

    public function role(ParticipantRole $role): static
    {
        return $this->state(fn (): array => ['participant_role' => $role]);
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
