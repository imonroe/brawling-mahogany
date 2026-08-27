<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CalendarFeed;
use App\Models\Person;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarFeed>
 */
class CalendarFeedFactory extends Factory
{
    use ForcesAttributes;

    protected $model = CalendarFeed::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(CalendarFeed::TOKEN_LENGTH);

        return [
            'team_id' => Team::factory(),
            'person_id' => Person::factory(),
            'deal_id' => null,
            'token' => $token,
            'token_hash' => CalendarFeed::hashToken($token),
            'name' => 'My calendar',
            'fetch_count' => 0,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subMinute()]);
    }
}
