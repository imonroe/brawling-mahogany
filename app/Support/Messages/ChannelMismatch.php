<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Enums\AutomationActionType;
use App\Enums\MessageChannel;
use App\Enums\RecipientRuleType;
use RuntimeException;

/**
 * An automation pointed at a template on the wrong channel.
 *
 * "Send an email" wants an email template. Pointed at a push one it would send
 * a lock-screen line with no subject and no HTML body — and PRD F12.2 keeps
 * push deliberately free of anything client-facing, so the two are not merely
 * different shapes but different audiences.
 *
 * Thrown rather than silently coerced, because coercing would send *something*
 * to a client, and PRD §4.5 is explicit that a wrong message cannot be
 * recalled.
 */
final class ChannelMismatch extends RuntimeException
{
    /**
     * The same mismatch, reached from the template's end.
     *
     * Editing a template's channel is the third caller — see
     * `MessageTemplate::booted()`. The message names what would break rather
     * than what was written, because the person here is editing words and has
     * probably forgotten an automation points at them.
     */
    public static function wouldStrand(AutomationActionType $action, MessageChannel $channel): self
    {
        return new self(sprintf(
            'An automation of type [%s] is using this template, and it cannot send on the [%s] channel.',
            $action->value,
            $channel->value,
        ));
    }

    /**
     * A recipient rule the channel is not allowed to carry.
     *
     * PRD F12.2: push is an internal channel and carries nothing
     * client-facing. Refused rather than rewritten, because there is no
     * *"who did you mean instead"* worth guessing on somebody's behalf.
     */
    public static function cannotCarryRecipient(MessageChannel $channel, RecipientRuleType $rule): self
    {
        return new self(sprintf(
            'A [%s] template cannot be addressed to [%s].',
            $channel->value,
            $rule->value,
        ));
    }

    public static function between(AutomationActionType $action, MessageChannel $channel): self
    {
        $wanted = $action->channel();

        return new self(sprintf(
            'An automation of type [%s] needs a [%s] template, not a [%s] one.',
            $action->value,
            $wanted instanceof MessageChannel ? $wanted->value : 'none',
            $channel->value,
        ));
    }
}
