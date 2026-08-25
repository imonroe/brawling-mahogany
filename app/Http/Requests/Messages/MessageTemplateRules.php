<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Enums\MessageChannel;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Models\MessageTemplate;
use App\Rules\ValidMergeFields;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What S46 collects, in one place so create and edit cannot drift.
 *
 * Three rules here are not ordinary field validation and are worth naming:
 *
 *  - **The channel narrows the form.** A push template has no subject line and
 *    no HTML body, so those fields are *prohibited* rather than merely
 *    optional. A stored subject on a channel that never renders one is a
 *    promise the product does not keep.
 *  - **The channel narrows the recipient.** PRD F12.2 keeps push internal, so
 *    a push template may not be addressed to a client. Asked through
 *    `RecipientRuleType::optionsFor()`, which is the same function the editor
 *    builds its picker from — a picker and a validator with separate lists
 *    disagree the first time one of them changes.
 *  - **Merge fields are validated at save time** (F5.6), per field, by
 *    {@see ValidMergeFields}.
 */
trait MessageTemplateRules
{
    /**
     * @return array<string, mixed>
     */
    protected function messageTemplateRules(?MessageTemplate $ignoring = null): array
    {
        $channel = $this->chosenChannel();

        return [
            'name' => ['required', 'string', 'max:120', $this->uniqueWithinTeam($ignoring)],

            'channel' => [
                'required',
                // The **selectable** list, not every case: `sms` is a value
                // PRD §7.12 names and nothing sends, and a template on a
                // channel with no transport can never leave the building.
                Rule::in(array_keys(MessageChannel::selectableOptions())),
            ],

            'subject' => $channel?->hasSubject() === true
                ? ['required', 'string', 'max:200', new ValidMergeFields]
                : ['prohibited'],

            'body_html' => $channel?->hasHtmlBody() === true
                ? ['nullable', 'string', 'max:100000', new ValidMergeFields]
                : ['prohibited'],

            // Never nullable. Design System §12 wants a real plain-text
            // alternative on every message, and the column is NOT NULL.
            'body_text' => ['required', 'string', 'max:100000', new ValidMergeFields],

            'recipient_rule' => ['required', 'array'],
            'recipient_rule.type' => [
                'required',
                Rule::in(array_keys(RecipientRuleType::optionsFor($channel ?? MessageChannel::Email))),
            ],
            'recipient_rule.participantRole' => [
                Rule::requiredIf(fn (): bool => $this->input('recipient_rule.type') === RecipientRuleType::ParticipantRole->value),
                Rule::excludeIf(fn (): bool => $this->input('recipient_rule.type') !== RecipientRuleType::ParticipantRole->value),
                Rule::enum(ParticipantRole::class),
            ],

            /*
             * A verified sending identity, when a team has one (#94). Held to
             * an address rather than a free string because it becomes a mail
             * header — and because SES will refuse anything else, later and
             * less legibly.
             */
            'from_identity' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    private function chosenChannel(): ?MessageChannel
    {
        $channel = $this->input('channel');

        return is_string($channel) ? MessageChannel::tryFrom($channel) : null;
    }

    /**
     * One live name per team **per channel**.
     *
     * Hand-written rather than `Rule::unique`, and the reason is the one
     * `DealTypeRules` records at length: the index is over `lower(name)` and
     * `Rule::unique` compares with `=`. Folding the bind in PHP instead is the
     * same defect one layer over — `mb_strtolower('ΑΣ')` is `ας` and Postgres
     * `lower()` gives `ασ` — so the comparison belongs in SQL, where the index
     * is.
     *
     * Both predicates too: `archived_at IS NULL` as well as `deleted_at`,
     * because archiving frees the name and a rule that filtered only one of
     * them refuses a create by pointing at a row the screen has badged
     * "Archived".
     */
    private function uniqueWithinTeam(?MessageTemplate $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $channel = $this->chosenChannel();

            if ($channel === null) {
                // The channel rule will fail on its own; a name check against
                // no channel would be a second, confusing error about a field
                // that is fine.
                return;
            }

            if (self::nameIsTaken(trim($value), $channel, $ignoring)) {
                $fail('You already have a template with this name on this channel.');
            }
        };
    }

    /**
     * The index's question, asked in the index's terms.
     *
     * Static and shared because the restore route has to ask it too: clearing
     * `archived_at` moves the row back *into* the partial index, so a restore
     * can violate it exactly the way a create can. S76 shipped that as a 500
     * and the fix was one implementation rather than two.
     */
    public static function nameIsTaken(string $name, MessageChannel $channel, ?MessageTemplate $ignoring = null): bool
    {
        $query = DB::table('message_templates')
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->where('team_id', app(TeamContext::class)->requireId(MessageTemplate::class))
            ->where('channel', $channel->value)
            ->whereRaw('lower(name) = lower(?)', [$name]);

        if ($ignoring instanceof MessageTemplate) {
            $query->where('id', '!=', $ignoring->getKey());
        }

        return $query->exists();
    }
}
