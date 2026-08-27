<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\Team;
use App\Support\Calendar\Recurrence;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => EventType::Showing,
            'title' => $this->faker->sentence(3),
            'starts_at' => now()->addDays($this->faker->numberBetween(0, 20))->setTime(10, 0),
            /*
             * **Null, not an hour after the default start.**
             *
             * A derived default is a trap here: a test that overrides
             * `starts_at` gets the *old* end, and `events_ends_after_start_check`
             * refuses the row — a constraint violation in a test about
             * something else entirely. Null is also the shape the model
             * documents as ordinary: `Event::endsIn()` fills an hour, in the
             * one place all three surfaces read it.
             */
            'ends_at' => null,
            'is_all_day' => false,
        ];
    }

    /**
     * An event with an end somebody actually chose.
     *
     * Takes the length rather than the instant, so it stays correct whatever
     * `starts_at` a caller sets — which is the defect the default above avoids
     * rather than reintroducing here.
     */
    public function lasting(int $minutes): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends_at' => CarbonImmutable::parse((string) $attributes['starts_at'])->addMinutes($minutes),
        ]);
    }

    public function allDay(): static
    {
        return $this->state(fn (): array => ['is_all_day' => true, 'ends_at' => null]);
    }

    public function weekly(?string $until = null): static
    {
        return $this->state(fn (): array => [
            'recurrence' => [
                'frequency' => Recurrence::WEEKLY,
                'interval' => 1,
                'until' => $until,
            ],
        ]);
    }
}
