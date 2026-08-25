<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Models\MessageTemplate;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    use ForcesAttributes;

    protected $model = MessageTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'channel' => MessageChannel::Email,
            'subject' => 'An update on {{ property_street }}',
            'body_html' => '<p>Hello {{ client_first_name }},</p><p>Here is where we are.</p>',
            // Design System §12: a real plain-text alternative, on every
            // message, never a stripped-tag afterthought.
            'body_text' => "Hello {{ client_first_name }},\n\nHere is where we are.",
            'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
            'from_identity' => null,
            'archived_at' => null,
        ];
    }

    public function toSeller(): static
    {
        return $this->state([
            'recipient_rule' => [
                'type' => RecipientRuleType::ParticipantRole->value,
                'participantRole' => ParticipantRole::Seller->value,
            ],
        ]);
    }

    /** A push template: no subject, no HTML, and an internal recipient. */
    public function push(): static
    {
        return $this->state([
            'channel' => MessageChannel::Push,
            'subject' => null,
            'body_html' => null,
            'body_text' => '{{ property_street }} has moved on.',
            'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
        ]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
