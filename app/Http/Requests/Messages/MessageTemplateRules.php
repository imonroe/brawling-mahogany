<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Enums\MessageChannel;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Models\MessageTemplate;
use App\Rules\SendableEmailAddress;
use App\Rules\ValidMergeFields;
use App\Support\Messages\MessageBodyLimits;
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
            ...self::fieldRules($channel),

            'name' => ['required', 'string', 'max:120', $this->uniqueWithinTeam($ignoring)],

            // The shared list plus the one check only an edit can make.
            'channel' => [
                ...self::fieldRules($channel)['channel'],
                $this->channelTheAutomationsCanUse($ignoring),
            ],
        ];
    }

    /**
     * The rules that are about the **fields**, and not about the request.
     *
     * Static and channel-in, because S46 is no longer the only way a message
     * template gets written: `ImportPack` writes one out of a pack file (#87),
     * and a second list of rules there would be the drift this codebase keeps
     * naming — *"a rule written into one caller is a rule the next caller is
     * written without"*. The importer converts its camelCase stanza to these
     * column names and validates against exactly this.
     *
     * What stays on the instance is what genuinely needs a request: the
     * per-team name uniqueness, and the check that a channel change does not
     * strand the automations already standing on the template being edited.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(?MessageChannel $channel): array
    {
        return [
            'channel' => [
                'required',
                // The **selectable** list, not every case: `sms` is a value
                // PRD §7.12 names and nothing sends, and a template on a
                // channel with no transport can never leave the building.
                Rule::in(array_keys(MessageChannel::selectableOptions())),
            ],

            'subject' => $channel?->hasSubject() === true
                ? [
                    'required',
                    'string',
                    'max:'.MessageBodyLimits::SUBJECT,
                    /*
                     * A subject line is a mail **header**, and the merged
                     * values are already stripped of CR and LF on the way into
                     * one. The template's own text was held to no such rule —
                     * so a subject carrying a newline stored, rendered, and
                     * reached the envelope. Symfony folds it into encoded
                     * words rather than injecting a header, so this is not an
                     * exploit; it is the same rule applied to the other half
                     * of the same string, and a mangled subject in the preview
                     * either way.
                     */
                    'not_regex:/[\r\n]/',
                    new ValidMergeFields,
                ]
                : ['prohibited'],

            'body_html' => $channel?->hasHtmlBody() === true
                // `markup: true` — a `<style>` block's nested CSS rules close
                // with `}}`, and refusing that would refuse valid email on the
                // one field HTML email is written into.
                ? ['nullable', 'string', 'max:'.MessageBodyLimits::HTML, new ValidMergeFields(markup: true)]
                : ['prohibited'],

            // Never nullable. Design System §12 wants a real plain-text
            // alternative on every message, and the column is NOT NULL.
            'body_text' => ['required', 'string', 'max:'.MessageBodyLimits::TEXT, new ValidMergeFields],

            'recipient_rule' => ['required', 'array'],
            'recipient_rule.type' => [
                'required',
                Rule::in(array_keys(RecipientRuleType::optionsFor($channel ?? MessageChannel::Email))),
            ],
            /*
             * The same pair, said without a closure over the request, because
             * these rules are now read by an importer as well as by a form.
             * `required_if` / `exclude_unless` name the sibling field, which
             * is what the closures were reading anyway.
             */
            'recipient_rule.participantRole' => [
                'required_if:recipient_rule.type,'.RecipientRuleType::ParticipantRole->value,
                'exclude_unless:recipient_rule.type,'.RecipientRuleType::ParticipantRole->value,
                Rule::enum(ParticipantRole::class),
            ],

            /*
             * A verified sending identity, when a team has one (#94). Held to
             * an address rather than a free string because it becomes a mail
             * header — and because SES will refuse anything else, later and
             * less legibly.
             */
            'from_identity' => ['nullable', 'email:rfc', 'max:255', new SendableEmailAddress],
        ];
    }

    /**
     * A channel change must not strand the automations already standing on
     * this template.
     *
     * `MessageTemplate::booted()` is what actually holds the invariant, for
     * every caller. This exists so the person editing gets a sentence on the
     * field instead of an exception — the model throws, and a throw is a 500
     * to somebody who changed a dropdown.
     */
    private function channelTheAutomationsCanUse(?MessageTemplate $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            $channel = is_string($value) ? MessageChannel::tryFrom($value) : null;

            if (! $ignoring instanceof MessageTemplate || $channel === null || $channel === $ignoring->channel) {
                return;
            }

            // The wide relation, matching the model guard — see its note.
            // A count that hides an automation is fine; a guard that does is
            // the hole the guard exists to close.
            $stranded = $ignoring->actionDefinitions()->get()->filter(
                fn ($automation): bool => $automation->action_type->channel() !== null
                    && $automation->action_type->channel() !== $channel,
            );

            if ($stranded->isNotEmpty()) {
                $fail(sprintf(
                    '%s %s using this template and cannot send on this channel. Point %s at another template first.',
                    $stranded->count(),
                    $stranded->count() === 1 ? 'automation is' : 'automations are',
                    $stranded->count() === 1 ? 'it' : 'them',
                ));
            }
        };
    }

    /**
     * The refusals that would otherwise name an attribute rather than a
     * reason.
     *
     * *"The selected recipient rule.type is invalid"* tells somebody nothing:
     * the rule they picked is a real rule, and what is wrong is that this
     * channel cannot carry it. IA §10 — an error says what happened, then what
     * to do.
     *
     * @return array<string, string>
     */
    protected function messageTemplateMessages(): array
    {
        return [
            'recipient_rule.type.in' => 'A push notification goes to the team, never to a client — PRD keeps it an internal channel.',
            'subject.prohibited' => 'This channel has no subject line.',
            'body_html.prohibited' => 'This channel carries plain text only.',
            'subject.not_regex' => 'A subject is a single line — take the line break out.',
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
