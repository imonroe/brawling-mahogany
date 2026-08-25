<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageChannel;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Messages\RecipientRule;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The words a team sends, and the rule for who gets them (PRD §4.5 F5.5, F5.6
 * · S45, S46 · issue #90).
 *
 * Independent of the automations that use it, per PRD §7.12 — the pointer runs
 * from {@see ActionDefinition} to here and never the other way. That is what
 * lets one "Inspection scheduled" template serve four automations on three
 * workflows.
 *
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property MessageChannel $channel
 * @property string|null $subject
 * @property string|null $body_html
 * @property string $body_text
 * @property array<string, mixed> $recipient_rule
 * @property string|null $from_identity
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'channel', 'subject', 'body_html', 'body_text', 'recipient_rule', 'from_identity'])]
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'recipient_rule' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The automations standing on this template.
     *
     * @return HasMany<ActionDefinition, $this>
     */
    public function actionDefinitions(): HasMany
    {
        return $this->hasMany(ActionDefinition::class);
    }

    /**
     * How many automations would be left without words if this went.
     *
     * S45's *"in use by N automations"* state, and the number is shown
     * **before** the choice rather than reported after it — the rule every
     * lookup screen in this product follows (Frontend conventions §4).
     *
     * Scoped by construction: `action_definitions` carries a `team_id` and the
     * relation is keyed through this row, which already belongs to one team.
     * `WorkflowTemplate::inUseCount()` needed its scope spelled out because a
     * shared template has no team of its own; this one always does.
     */
    public function inUseCount(): int
    {
        return $this->actionDefinitions()->count();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * What S44's picker may offer.
     *
     * The pickers are a suggestion, not the guarantee — `ActionDefinition`
     * refuses an archived template on save, for the reason S76 found the hard
     * way: an id posted by hand, or held in a form somebody left open while a
     * colleague archived the template, reached the database unopposed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** The rule as a value object rather than an array of unknown shape. */
    public function recipientRule(): RecipientRule
    {
        return RecipientRule::fromArray($this->recipient_rule);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnChannel(Builder $query, MessageChannel $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
