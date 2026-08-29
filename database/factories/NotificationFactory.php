<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Person;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'type' => NotificationType::TaskAssigned,
            'deal_id' => null,
            'summary' => 'Order the survey',
            'data' => [],
            'channels' => [NotificationChannel::InApp->value],
            'deliver_after' => null,
            'delivered_at' => now(),
            'read_at' => null,
        ];
    }

    public function read(): self
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function held(): self
    {
        return $this->state(fn (): array => [
            'channels' => [NotificationChannel::InApp->value, NotificationChannel::Email->value],
            'deliver_after' => now()->addHours(8),
            'delivered_at' => null,
        ]);
    }
}
