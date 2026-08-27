<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\Person;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    use ForcesAttributes;

    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'channels' => [],
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ];
    }

    /** The evening most people would set, wrapping midnight. */
    public function quiet(string $start = '21:00', string $end = '07:00'): self
    {
        return $this->state(fn (): array => [
            'quiet_hours_start' => $start,
            'quiet_hours_end' => $end,
        ]);
    }
}
