<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * How a message reaches somebody (PRD §6.3, §7.12 · issue #90).
 *
 * PRD §7.12 is the correction this implements: *"`Email Template` points the
 * wrong way, and should generalise"*, and the v0.2 update adds `push` beside
 * `email` and the deferred `sms`. A template is therefore a message template
 * with a channel, not an email template — which is why the model, the table
 * and the route all say *message*.
 *
 * ## `sms` is a case and is not selectable, and both halves are deliberate
 *
 * It is a case because the PRD names it and because a channel added later is
 * a channel every stored `recipient_rule` and every action definition was
 * written without. It is not selectable because nothing sends one: an editor
 * offering it would let somebody compose a template that can never leave the
 * building, which is `CLAUDE.md`'s S17 finding — *"a row nothing can reach is
 * a rule nobody is following"* — pointed the other way round.
 *
 * Same shape as {@see \App\Support\Workflow\Gates\GateRegistry::selectableOptions()},
 * and for the same reason.
 */
enum MessageChannel: string implements HasLabel
{
    use ProvidesOptions;

    case Email = 'email';
    case Push = 'push';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Push => 'Push',
            self::Sms => 'SMS',
        };
    }

    /**
     * Whether a channel carries a subject line.
     *
     * A push notification has a title and a body and no subject; S46 hides the
     * field rather than showing one that is stored and never rendered, and the
     * validator refuses a subject on a channel that has none.
     */
    public function hasSubject(): bool
    {
        return $this === self::Email;
    }

    /**
     * Whether a channel carries HTML.
     *
     * Only email does. A push payload is text on a lock screen, and PRD §9
     * keeps PII off it entirely — see {@see \App\Support\Messages\MergeFields}.
     */
    public function hasHtmlBody(): bool
    {
        return $this === self::Email;
    }

    /**
     * Whether this product can actually deliver on this channel today.
     *
     * `sms` is deferred (PRD §7.12), so it is legible and not offerable.
     */
    public function isDeliverable(): bool
    {
        return $this !== self::Sms;
    }

    /**
     * What S46's picker may offer — never the full list.
     *
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->isDeliverable()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
