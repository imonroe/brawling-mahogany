<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\ActionDefinition;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionDefinition>
 */
class ActionDefinitionFactory extends Factory
{
    use ForcesAttributes;

    protected $model = ActionDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * A task rather than an email by default, so a factory with no
         * arguments produces a **complete** automation — one that needs no
         * message template to make sense. Testing conventions §5: *"every
         * factory produces a valid record with no arguments."*
         */
        return [
            'team_id' => null,
            'trigger' => AutomationTrigger::StageStart,
            'action_type' => AutomationActionType::CreateTask,
            'message_template_id' => null,
            'config' => ['taskTitle' => 'Order the survey'],
            'requires_approval' => false,
            'is_manual' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function sendingEmail(): static
    {
        return $this->state([
            'action_type' => AutomationActionType::SendEmail,
            'config' => [],
        ]);
    }

    /** F5.10's arrangement: prepared automatically, released by a human. */
    public function needingApproval(): static
    {
        return $this->state(['requires_approval' => true, 'is_manual' => false]);
    }

    public function manual(): static
    {
        return $this->state(['is_manual' => true, 'requires_approval' => false]);
    }
}
