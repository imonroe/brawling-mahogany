<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Enums\AutomationActionType;
use App\Enums\MessageChannel;
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
