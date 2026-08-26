<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Models\ActionInstance;
use App\Models\Deal;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActionInstance>
 */
class ActionInstanceFactory extends Factory
{
    use ForcesAttributes;

    protected $model = ActionInstance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * A complete, sendable email by default. Testing conventions §5 wants
         * a factory with no arguments to produce a valid record, and for this
         * table "valid" has to mean *the rails would let it through* — a
         * default whose merge fields were unresolved would make every test
         * that did not think about it assert on a refusal.
         */
        return [
            'deal_id' => Deal::factory(),
            'stage_id' => null,
            'action_definition_id' => null,
            'action_type' => AutomationActionType::SendEmail,
            'trigger' => AutomationTrigger::StageCompletion,
            'message_template_id' => null,
            'config' => [],
            'state' => AutomationState::Pending,
            'payload' => [
                'subject' => 'Your inspection is scheduled',
                'bodyHtml' => '<p>The inspection is booked for Friday.</p>',
                'bodyText' => 'The inspection is booked for Friday.',
                'unresolved' => [],
                'unknown' => [],
                'malformed' => [],
                'templateName' => 'Inspection scheduled',
                'recipients' => [
                    ['name' => 'Dana Okafor', 'email' => 'dana@example.test'],
                ],
            ],
            'attempts' => 0,
        ];
    }

    public function awaitingApproval(): static
    {
        return $this->state(['state' => AutomationState::AwaitingApproval]);
    }

    public function sent(): static
    {
        return $this->state([
            'state' => AutomationState::Sent,
            'executed_at' => now(),
            'message_key' => (string) Str::ulid(),
            'attempts' => 1,
        ]);
    }

    public function failed(string $error = 'This message resolved to nobody on this deal.'): static
    {
        return $this->state([
            'state' => AutomationState::Failed,
            'executed_at' => now(),
            'error' => $error,
            'attempts' => 1,
        ]);
    }

    /** A message nobody can send: the defect PR #175's review found. */
    public function withAStrayBrace(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                ...(is_array($attributes['payload'] ?? null) ? $attributes['payload'] : []),
                'bodyText' => 'Hello {{ client_name }, your inspection is booked.',
                'malformed' => ['{{'],
            ],
        ]);
    }

    public function creatingATask(string $title = 'Order the survey'): static
    {
        return $this->state([
            'action_type' => AutomationActionType::CreateTask,
            'config' => ['taskTitle' => $title],
            'payload' => [],
        ]);
    }
}
