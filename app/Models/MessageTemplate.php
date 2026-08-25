<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\RecipientRuleType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Messages\ChannelMismatch;
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

    protected static function booted(): void
    {
        /*
         * The channel is half of a pair, and this is the third caller.
         *
         * `SaveAutomationRequest` and `ActionDefinition::booted()` both refuse
         * an automation whose action does not match its template's channel —
         * and both look at the *automation* end. Editing the **template's**
         * channel reaches the same broken state from the other side, and
         * `ActionDefinition::saving` never fires because no automation row is
         * being written. A `send_email` automation was left pointing at a push
         * template through the front door, with a 302.
         *
         * This is the same finding this codebase keeps making — a rule written
         * into one caller is a rule the next caller is written without — so
         * the invariant goes where both ends have to pass: on the model, not
         * in the request that happened to be found.
         */
        static::saving(function (self $template): void {
            if (! $template->isDirty('channel')) {
                return;
            }

            /*
             * A channel that carries no subject must not leave one behind.
             *
             * `MessageTemplateRules` marks the field `prohibited` rather than
             * optional because *"a stored subject on a channel that never
             * renders one is a promise the product does not keep"* —
             * `prohibited` stops the write and nothing cleared the column, so
             * a template switched to push kept its old subject and HTML body.
             */
            if (! $template->channel->hasSubject()) {
                $template->subject = null;
            }

            if (! $template->channel->hasHtmlBody()) {
                $template->body_html = null;
            }

            /*
             * The recipient rule is narrowed by channel the same way, and is
             * **refused** rather than cleared.
             *
             * PRD F12.2 keeps push internal, so a push template addressed to a
             * client is the state that rule exists to prevent — and unlike a
             * subject, which a channel with none simply does not have, there
             * is no answer to *"who did you mean instead"* that we could pick
             * for somebody. The front door already refuses it in validation;
             * this is for the callers the block exists for, #92's
             * instantiation and a pack install.
             */
            $rule = RecipientRule::tryFromArray($template->recipient_rule);

            if ($rule !== null && ! array_key_exists(
                $rule->type->value,
                RecipientRuleType::optionsFor($template->channel),
            )) {
                throw ChannelMismatch::cannotCarryRecipient($template->channel, $rule->type);
            }

            if (! $template->exists) {
                return;
            }

            /*
             * **Every** automation, not just the reachable ones.
             *
             * The count on S45 is deliberately narrower — an automation on a
             * soft-deleted workflow template is a number nobody can chase down
             * — but a guard that borrowed that narrowing reopened the hole one
             * step back: delete the workflow template, change the channel, and
             * the automation survives pointing at a template it cannot send.
             * PRD §9 gives a soft delete a 30-day window, so those rows come
             * back.
             *
             * The count answers *what should I tell the reader*; the guard
             * answers *what would this break*. They are different questions
             * and only one of them may be optimistic.
             */
            $stranded = $template->actionDefinitions()->get()->first(
                fn (ActionDefinition $automation): bool => $automation->action_type->channel() !== null
                    && $automation->action_type->channel() !== $template->channel,
            );

            if ($stranded instanceof ActionDefinition) {
                throw ChannelMismatch::wouldStrand($stranded->action_type, $template->channel);
            }
        });
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
     * The automations somebody could actually still reach.
     *
     * `WorkflowTemplateController::destroy()` soft-deletes the workflow
     * template and touches neither its stages nor their automations, so the
     * plain relation counts automations that exist on no screen. The number
     * S45 shows is the one thing that screen is for — *"3 automations keep it
     * and go on sending it"* about three nobody can open is worse than no
     * number, because Frontend conventions §4 puts the count before the choice
     * precisely so it can be acted on.
     *
     * Read by the **count** and by nothing else. The channel guard above
     * deliberately asks the wider relation — see the note there.
     *
     * @return HasMany<ActionDefinition, $this>
     */
    public function liveActionDefinitions(): HasMany
    {
        return $this->actionDefinitions()->whereHas('stageTemplate.workflowTemplate');
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
        return $this->liveActionDefinitions()->count();
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
}
