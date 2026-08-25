<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Models\Stage;
use App\Support\Formatting\Format;
use RuntimeException;

/**
 * The merge fields a template may use, and what each resolves to (F5.6).
 *
 * > Client name · address · MLS link · agent contact block · stage · key dates
 * > · **status page link**
 * >
 * > **Validated at save time.** An invalid merge field is a broken email to a
 * > real client, discovered too late.
 *
 * ## Two of F5.6's seven cannot resolve yet, and they are registered anyway
 *
 * Key dates arrive in Slice 4 (#109) and the status page in Slice 4 (#110).
 * They are here, carrying the slice that wires them, for the same reason the
 * deferred gate evaluators are real classes: the editor can then say *which*
 * slice rather than "unknown field", and the validator refuses them **by
 * name** rather than by silence. A template that could carry one would produce
 * a client email with a dead link in it.
 *
 * ## Why the stage field is the client-facing name and there is no other
 *
 * IA §9 is absolute: the client sees a stage's `milestone_label`, never the
 * internal name — internal names say things like *"Chase lender"*. A message
 * template is a client-communication instrument, so `{{ stage }}` resolves to
 * the client-facing label and the internal one is **not offered at all**. An
 * internal channel that wants the internal name can have its own field the day
 * something needs one; a field that exists is a field somebody will use.
 */
final class MergeFields
{
    /**
     * Loose on purpose: it matches anything between double braces, not just a
     * well-formed token.
     *
     * `{{ client name }}` is not a valid token and would otherwise pass
     * validation untouched and render **literally** into a client's inbox. The
     * strict shape is checked afterwards, against what this finds.
     */
    private const TOKEN_PATTERN = '/\{\{(.*?)\}\}/s';

    private const WELL_FORMED = '/^[a-z][a-z0-9_]*$/';

    /**
     * A brace run that is not part of a matched pair.
     *
     * `TOKEN_PATTERN` is loose about *what is between* the braces and strict
     * about the braces themselves, which turned out to be exactly half a
     * defence. `{{ client_name }` matches nothing, so the validator saw a
     * clean template and the renderer left the braces in a client's inbox —
     * and `RenderedMessage::isComplete()` said the message was fine, which is
     * the flag #93's approval gate is built on.
     *
     * Dropping one brace is the likeliest typo in a token somebody hand-types,
     * so it is checked after the pairs are removed rather than by widening the
     * pattern: what is left over is by definition unbalanced.
     */
    private const BRACE_RUN = '/\{\{|\}\}/';

    /**
     * What a body of **markup** may legitimately contain braces inside.
     *
     * `<style>@media (max-width:600px){.c{width:100%}}</style>` is ordinary
     * email CSS — Design System §12 wants a `<style>` block as a progressive
     * enhancement — and it closes two nested rules with `}}`. Reporting that
     * as a stray brace refused a valid template on the one field HTML email is
     * written into.
     *
     * The first fix for that dropped the closing half of the check on markup
     * fields, and **gave up more than it looked like**: `{ client_name }}`,
     * one opening brace instead of two, is exactly as likely as
     * `{{ client_name }` and needs no odd spacing to produce. It saved clean,
     * rendered the braces into a client's inbox, and reported itself complete
     * — which is round 1's original finding, alive on the one field that
     * matters most.
     *
     * So the blocks come out instead and **both halves stay**. Only
     * `strayBraceRuns()` strips them; `extract()` does not, so an unknown
     * merge field written inside a `<style>` block is still refused by name.
     */
    private const MARKUP_BLOCKS = '#<(style|script)\b[^>]*>.*?</\1\s*>#is';

    /**
     * @return list<MergeField>
     */
    public static function all(): array
    {
        return [
            new MergeField(
                'client_name',
                'Client name',
                'Client',
                'The deal’s main contact, in full.',
            ),
            new MergeField(
                'client_first_name',
                'Client first name',
                'Client',
                'Just the first name, for a greeting.',
            ),
            new MergeField(
                'property_address',
                'Property address',
                'Property',
                'The subject property on one line.',
            ),
            new MergeField(
                'property_street',
                'Property street',
                'Property',
                'The street line on its own.',
            ),
            new MergeField(
                'mls_link',
                'MLS link',
                'Property',
                'The subject property’s MLS listing, when one has been linked.',
            ),
            new MergeField(
                'stage',
                'Stage',
                'Deal',
                'What the client is told this stage is called (IA §9 — never the internal name).',
            ),
            new MergeField(
                'deal_name',
                'Deal name',
                'Deal',
                'What this deal is called.',
            ),
            new MergeField(
                'team_name',
                'Team name',
                'Team',
                'Your team’s name.',
            ),
            new MergeField(
                'agent_contact_block',
                'Agent contact block',
                'Team',
                'Your signature block from team settings.',
                multiline: true,
            ),
            /*
             * **Not `next_key_date`.** IA §11 bans "Key dates" in the UI in
             * Emily's own phrase, and a merge field's label is UI — its token
             * is more so, because somebody types it into a message body.
             */
            new MergeField(
                'next_deadline',
                'Next deadline',
                'Deal',
                'The next date somebody has to act by on this deal.',
                availableFrom: 'Dates & Deadlines arrive in Slice 4 (#109).',
            ),
            new MergeField(
                'status_page_link',
                'Status page link',
                'Client',
                'The client’s own read-only view of this deal.',
                availableFrom: 'The Status Page arrives in Slice 4 (#110).',
            ),
        ];
    }

    /**
     * What S46's picker may offer, and what a body may contain.
     *
     * @return list<MergeField>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (MergeField $field): bool => $field->isAvailable(),
        ));
    }

    public static function find(string $token): ?MergeField
    {
        foreach (self::all() as $field) {
            if ($field->token === $token) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Every `{{ … }}` run in a body, in the order they appear, deduplicated.
     *
     * Returns what was written **between** the braces, trimmed and otherwise
     * untouched — so a malformed token comes back malformed and the caller can
     * say so. Returning only well-formed tokens here is how a body with
     * `{{ client name }}` in it passes validation.
     *
     * @return list<string>
     */
    public static function extract(?string ...$bodies): array
    {
        $found = [];

        foreach ($bodies as $body) {
            if ($body === null || $body === '') {
                continue;
            }

            /*
             * A failure here reports **no tokens**, which reads as a template
             * with no merge fields at all — so an unknown field would pass
             * validation. The same fail-open direction as the two above.
             */
            if (preg_match_all(self::TOKEN_PATTERN, $body, $matches) === false) {
                throw new RuntimeException('The merge fields in this message could not be read.');
            }

            foreach ($matches[1] as $raw) {
                $token = trim($raw);

                if (! in_array($token, $found, true)) {
                    $found[] = $token;
                }
            }
        }

        return $found;
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::WELL_FORMED, $token) === 1;
    }

    /**
     * Replace every `{{ … }}` run in one pass, token by token.
     *
     * One pass rather than a `preg_replace` per token, and the difference is
     * not tidiness. Replacing token by token walks over the text it has
     * already written, so a merged value that happens to contain braces —
     * a person whose name in the directory reads `{{ team_name }}` — would be
     * substituted again by a later iteration. A callback's return value is
     * literal, which also removes the `$` and `\` back-reference escaping a
     * `preg_replace` replacement needs and is easy to forget.
     *
     * @param  callable(string): string  $replace  Receives the trimmed token, returns what to put in its place.
     */
    public static function substitute(string $body, callable $replace): string
    {
        /*
         * A PCRE failure returns null, and `(string) null` is an **empty
         * message**. The body with its braces still in it is wrong and
         * visible; an empty one is wrong and silent, and this is the last step
         * before the words go out.
         */
        return preg_replace_callback(
            self::TOKEN_PATTERN,
            static fn (array $match): string => $replace(trim($match[1])),
            $body,
        ) ?? $body;
    }

    /**
     * The unbalanced brace runs left over once every matched pair is removed.
     *
     * `{{ client_name }` and `{{client_name` leave a `{{`; `{ { client_name }}`
     * leaves a `}}`. A single stray `}` is not reported — `{{ client_name }}}`
     * is a token followed by a literal brace, which renders as somebody
     * probably meant and is not the failure this exists to catch.
     *
     * `$markup` takes `<style>` and `<script>` blocks out first, for a field
     * that may legitimately carry nested CSS braces inside one. See
     * {@see self::MARKUP_BLOCKS}.
     *
     * @return list<string>
     */
    public static function strayBraceRuns(?string $body, bool $markup = false): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        if ($markup) {
            $stripped = preg_replace(self::MARKUP_BLOCKS, '', $body);

            /*
             * A PCRE failure fails **closed**.
             *
             * `preg_replace` returns null when the engine gives up — a
             * backtrack limit, a pathological body — and `(string) null` is
             * `''`, which is a body with no braces in it and therefore a clean
             * save on the one field this check exists for. `CLAUDE.md` records
             * that direction twice already: an exclusion list fails open, and
             * did. Scanning the unstripped body may refuse a valid `<style>`
             * block, which is a message somebody can act on rather than a
             * silent yes.
             */
            $body = $stripped ?? $body;
        }

        /*
         * And the same direction on the pair-removal, which the first fix left
         * behind: a null here would have become `''`, a body with no braces in
         * it, and a clean save. Falling back to the **unremoved** body means a
         * well-formed token's own braces are reported as stray — a message
         * about the wrong thing, which is still a message rather than a
         * silent yes.
         */
        $remainder = preg_replace(self::TOKEN_PATTERN, '', $body) ?? $body;

        preg_match_all(self::BRACE_RUN, $remainder, $matches);

        $found = [];

        foreach ($matches[0] as $run) {
            if (! in_array($run, $found, true)) {
                $found[] = $run;
            }
        }

        return $found;
    }

    /**
     * The values, for one deal.
     *
     * Raw — **never** escaped here. Escaping is the renderer's job because it
     * depends on where the value lands: a client's name goes through
     * `htmlspecialchars` on its way into `body_html` and untouched into
     * `body_text`, and escaping at this layer would put `&amp;` into the plain
     * text alternative of every message sent to the O'Brien household.
     *
     * A field with nothing behind it resolves to an **empty string** and is
     * reported separately by {@see RenderMessage}, so the preview can show the
     * gap rather than leaving `{{ mls_link }}` in the body.
     *
     * @return array<string, string>
     */
    public static function resolve(MergeContext $context): array
    {
        $deal = $context->deal;
        $deal->loadMissing(['participants.membership', 'propertyLinks.property.externalLinks']);

        $contact = self::primaryContact($context);
        $property = self::subjectProperty($context);

        return [
            'client_name' => $contact?->fullName() ?? '',
            'client_first_name' => $contact?->membership->first_name ?? '',
            'property_address' => Format::addressOneLine($property),
            'property_street' => $property instanceof Property ? (string) $property->street : '',
            'mls_link' => self::mlsLink($property),
            'stage' => self::clientFacingStage($context),
            'deal_name' => $deal->displayName(),
            'team_name' => $context->team->name,
            'agent_contact_block' => self::contactBlock($context),
        ];
    }

    /**
     * The same order `DealHeader::clientName()` uses, and deliberately so.
     *
     * A message that greets a different person from the one the header names
     * would be the two-payloads problem #75 folded away, reappearing in the
     * one place it cannot be corrected after the fact.
     */
    private static function primaryContact(MergeContext $context): ?DealParticipant
    {
        $participants = $context->deal->participants;

        $participant = $participants->firstWhere('is_primary', true) ?? $participants->first();

        return $participant instanceof DealParticipant ? $participant : null;
    }

    private static function subjectProperty(MergeContext $context): ?Property
    {
        $link = $context->deal->propertyLinks->firstWhere('is_subject', true);

        return $link instanceof DealProperty ? $link->property : null;
    }

    /**
     * The property's MLS listing, as a link and never as a copy of what is on
     * the other end.
     *
     * PRD §10 and `CLAUDE.md`: *"v1 stores links only, never ingested listing
     * content."* This resolves to a URL, which is the only thing
     * `external_links` holds — the table has no column for a price, a photo or
     * a description, and this field could not render one if it wanted to.
     *
     * The URL was held to an http/https allowlist by `SafeUrl` on the way in,
     * so a stored `javascript:` URL cannot reach an email's `href`.
     */
    private static function mlsLink(?Property $property): string
    {
        if (! $property instanceof Property) {
            return '';
        }

        $mls = $property->externalLinks->first(
            static fn (ExternalLink $link): bool => str_contains(mb_strtolower($link->label), 'mls'),
        ) ?? $property->externalLinks->first();

        return $mls instanceof ExternalLink ? $mls->url : '';
    }

    /**
     * IA §9's client vocabulary, or nothing.
     *
     * Empty rather than the internal name when a stage carries no
     * `milestone_label`, because "nothing" is a gap the preview reports and an
     * author can fix, and the internal name is a leak they would never see.
     */
    private static function clientFacingStage(MergeContext $context): string
    {
        $stage = $context->stage;

        if (! $stage instanceof Stage) {
            return '';
        }

        return $stage->milestone_label ?? '';
    }

    /**
     * F5.6's *agent contact block*, from the team's signature.
     *
     * A deal has no assigned agent column today — the team is the unit that
     * has a sending identity and a signature (PRD §8.5, `teams.signature_block`)
     * — so this is the team's block, which is where a team writes the agent's
     * name, number and licence. When a deal grows an owner, this field resolves
     * from it instead and no template changes.
     */
    private static function contactBlock(MergeContext $context): string
    {
        $team = $context->team;

        if (($team->signature_block ?? '') !== '') {
            return (string) $team->signature_block;
        }

        return trim(implode("\n", array_filter([
            $team->sending_identity_name ?? $team->name,
            $team->sending_identity_email,
        ])));
    }
}
