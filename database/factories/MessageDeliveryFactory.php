<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Models\ActionInstance;
use App\Models\MessageDelivery;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageDelivery>
 */
class MessageDeliveryFactory extends Factory
{
    use ForcesAttributes;

    protected $model = MessageDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_instance_id' => ActionInstance::factory(),
            'team_membership_id' => null,
            'recipient_email' => $this->faker->unique()->safeEmail(),
            'channel' => MessageChannel::Email,
            'provider_message_id' => (string) Str::ulid(),
            'status' => DeliveryStatus::Sent,
        ];
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Delivered,
            'delivered_at' => now(),
        ]);
    }

    public function bounced(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Bounced,
            'bounced_at' => now(),
            'detail' => 'smtp; 550 5.1.1 user unknown',
        ]);
    }
}
