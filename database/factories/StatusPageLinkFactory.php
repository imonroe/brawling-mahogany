<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deal;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Models\TeamMembership;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPageLink>
 */
class StatusPageLinkFactory extends Factory
{
    use ForcesAttributes;

    protected $model = StatusPageLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'deal_id' => Deal::factory(),
            'team_membership_id' => TeamMembership::factory(),
            /*
             * A hash of something random rather than of a fixture string: two
             * links built by the same factory must not collide on the unique
             * index, and a test that needs to *use* a token issues it through
             * `IssueStatusPageLink` — which is the only thing that ever knows
             * a plaintext.
             */
            'token_hash' => StatusPageLink::hashToken(Str::random(40)),
            'expires_at' => now()->addMinutes(StatusPageLink::LINK_MINUTES),
            'view_count' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()->subMinute()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subMinute()]);
    }
}
