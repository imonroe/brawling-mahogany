<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Models\MessageTemplate;

/**
 * Template plus deal in, message out (F5.6, F5.10 · S46, S48).
 *
 * The one place substitution happens, so the preview a person approves and the
 * message that actually goes are produced by the same code. Two renderers
 * would be F5.7's whole safety net resting on a coincidence.
 *
 * ## Escaping is decided here and nowhere else
 *
 * `MergeFields::resolve()` returns raw values on purpose, because where a
 * value lands decides what has to happen to it:
 *
 *  - **into `body_html`** — escaped, and line breaks turned into `<br>` for
 *    the fields that carry them. A client called *Fish & Sons LLC* otherwise
 *    produces invalid markup, and one whose name contains a `<` is stored XSS
 *    in the approval preview a colleague opens.
 *  - **into `body_text`** — untouched. Escaping here would put `&amp;` into
 *    the plain-text alternative Design System §12 requires of every message.
 *  - **into `subject`** — escaped for neither, and stripped of CR and LF.
 *    A subject line is a mail **header**: a newline in a merged value is
 *    header injection, and the value comes from a field somebody typed into
 *    the people directory.
 *
 * The template body itself is not escaped. It is written by somebody holding
 * `templates.manage` and it is their email — but it is also rendered back into
 * this application on S46 and S48, so the **preview renders it in a sandboxed
 * iframe** rather than through `v-html`. Trusting an author with their own
 * outbound HTML is not the same as trusting it inside a colleague's session.
 */
final class RenderMessage
{
    public function render(MessageTemplate $template, MergeContext $context): RenderedMessage
    {
        $values = MergeFields::resolve($context);

        $unresolved = [];
        $unknown = [];

        foreach (MergeFields::extract($template->subject, $template->body_html, $template->body_text) as $token) {
            $field = MergeFields::isWellFormed($token) ? MergeFields::find($token) : null;

            if ($field === null || ! $field->isAvailable()) {
                $unknown[] = $token;

                continue;
            }

            if (($values[$token] ?? '') === '') {
                $unresolved[] = $token;
            }
        }

        return new RenderedMessage(
            subject: $template->channel->hasSubject()
                ? $this->substitute($template->subject, $values, self::asHeader(...))
                : null,
            bodyHtml: $template->channel->hasHtmlBody()
                ? $this->substitute($template->body_html, $values, self::asHtml(...))
                : null,
            bodyText: (string) $this->substitute($template->body_text, $values, self::asText(...)),
            unresolved: $unresolved,
            unknown: $unknown,
        );
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string, bool): string  $escape
     */
    private function substitute(?string $body, array $values, callable $escape): ?string
    {
        if ($body === null) {
            return null;
        }

        return MergeFields::substitute($body, static function (string $token) use ($values, $escape): string {
            $field = MergeFields::isWellFormed($token) ? MergeFields::find($token) : null;

            /*
             * The braces go whatever the token was, including the ones nothing
             * answers to. `{{ client name }}` left in place is a template's
             * internals arriving in somebody's inbox; blanked, it is a gap the
             * caller has already been told about in `RenderedMessage::$unknown`
             * and must not send over.
             */
            $value = $field !== null && $field->isAvailable() ? ($values[$token] ?? '') : '';

            return $escape($value, $field instanceof MergeField && $field->multiline);
        });
    }

    private static function asText(string $value, bool $multiline): string
    {
        return $value;
    }

    private static function asHtml(string $value, bool $multiline): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $multiline ? nl2br($escaped, false) : $escaped;
    }

    /** CR and LF out of a mail header — see the class docblock. */
    private static function asHeader(string $value, bool $multiline): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
