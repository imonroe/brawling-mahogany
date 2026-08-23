<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Person;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
{
    use ForcesAttributes;

    protected $model = ActivityEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'subject_type' => (new Person)->getMorphClass(),
            'subject_id' => Person::factory(),
            'event_type' => 'contact.logged',
            'source' => ActivitySource::Manual->value,
            'occurred_at' => now(),
            'summary' => 'Phone call',
            'payload' => [],
            // Mirrors the column default deliberately: a factory that opted
            // people's timelines into client visibility would hide the very
            // regression issue #50 asks for a test on.
            'is_client_visible' => false,
        ];
    }
}
