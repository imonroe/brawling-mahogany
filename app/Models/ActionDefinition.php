<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Messages\ChannelMismatch;
use App\Support\Tenancy\ArchivedReferenceException;
use Database\Factories\ActionDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An automation, as a team defines it (PRD §4.5 F5.1–F5.4 · S44 · issue #91).
 *
 * The definition layer, hanging off a `stage_template`. Instantiating a
 * workflow snapshots it into an `action_instance` (#92); nothing at runtime
 * reads back here, exactly as nothing at runtime reads a `stage_template`.
 *
 * Not `BelongsToTeam`, and the migration argues it at length: `team_id` is
 * nullable because it mirrors the parent stage template's, and a null one is a
 * pack row shared by every team. A composite foreign key and a CHECK
 * constraint together stop such a row ever naming one team's message
 * template.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $stage_template_id
 * @property AutomationTrigger $trigger
 * @property AutomationActionType $action_type
 * @property string|null $message_template_id
 * @property array<string, mixed>|null $config
 * @property bool $requires_approval
 * @property bool $is_manual
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'trigger',
    'action_type',
    'message_template_id',
    'config',
    'requires_approval',
    'is_manual',
    'is_active',
])]
class ActionDefinition extends Model
{
    /** @use HasFactory<ActionDefinitionFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'action_type' => AutomationActionType::class,
            'config' => 'array',
            'requires_approval' => 'boolean',
            'is_manual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
         * A template that cannot be sent must not be attached.
         *
         * On the model rather than in the controller, for the reason ADR 0002
         * already records and `HasDocuments` repeats: a rule written into one
         * caller is a rule the second caller is written without. The second
         * caller here is #92's instantiation and, later, a pack install.
         *
         * S76 found the first half the hard way — `scopeSelectable()` existed
         * for the pickers and had no production caller, so an archived id
         * posted by hand was accepted. The second half is narrower and
         * sharper: an email automation pointed at a **push** template would
         * put a lock-screen line where an HTML email should be, and nothing
         * would notice until a client received it.
         */
        static::saving(function (self $definition): void {
            if (! $definition->isDirty('message_template_id') || $definition->message_template_id === null) {
                return;
            }

            $template = MessageTemplate::query()->whereKey($definition->message_template_id)->first();

            if (! $template instanceof MessageTemplate) {
                return;
            }

            if ($template->isArchived()) {
                throw ArchivedReferenceException::for('message_templates', $template->getKey());
            }

            $wanted = $definition->action_type->channel();

            if ($wanted !== null && $template->channel !== $wanted) {
                throw ChannelMismatch::between($definition->action_type, $template->channel);
            }
        });
    }

    /**
     * @return BelongsTo<StageTemplate, $this>
     */
    public function stageTemplate(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class);
    }

    /**
     * @return BelongsTo<MessageTemplate, $this>
     */
    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function isSystem(): bool
    {
        return $this->team_id === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->config ?? [];
    }

    /**
     * How a human is put in the loop, as **one** answer rather than two
     * booleans.
     *
     * F5.4's manual prompt and F5.7's approval queue are the same moment from
     * two ends, and the table refuses to hold both at once. The screens ask
     * this rather than reading the columns, so nothing has to remember that
     * `is_manual && requires_approval` is a state that cannot exist.
     */
    public function executionMode(): string
    {
        return match (true) {
            $this->is_manual => 'manual',
            $this->requires_approval => 'approval',
            default => 'automatic',
        };
    }

    /**
     * Whether this automation is fully specified and could actually fire.
     *
     * An automation that sends words needs words. Deleting a message template
     * nulls the pointer rather than cascading (see the migration), so an
     * automation can legitimately arrive here missing one — and S44 shows it
     * as needing attention rather than pretending it will run.
     */
    public function isComplete(): bool
    {
        if (! $this->action_type->needsMessageTemplate()) {
            return true;
        }

        return $this->message_template_id !== null;
    }

    /**
     * A sentence describing what this does, for S44's list and S41's editor.
     *
     * Built here so the two screens cannot describe the same automation
     * differently — and in the IA §11 vocabulary, which is why nothing in it
     * says "trigger" or "action".
     */
    public function describe(): string
    {
        $what = $this->action_type->label();

        if ($this->action_type->needsMessageTemplate()) {
            $name = $this->messageTemplate?->name;

            $what .= $name === null ? ' — no template chosen yet' : ' — “'.$name.'”';
        }

        return $this->trigger->label().': '.mb_strtolower(mb_substr($what, 0, 1)).mb_substr($what, 1);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
