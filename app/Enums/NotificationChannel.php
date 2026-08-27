<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;
use App\Support\Push\SendPush;

/**
 * How a notification reaches somebody **inside** the team (F12.4 · issue #101).
 *
 * ## Why this is not {@see MessageChannel}
 *
 * They look alike and answer different questions, which IA §11's one-concept-
 * one-word rule makes worth separating rather than sharing. `MessageChannel`
 * is how a **client** is written to: it carries a subject line, an HTML body
 * and a plain-text alternative, and everything on it goes through F5.7's
 * approval queue and F5.9's rails because an email to the wrong client cannot
 * be recalled. This is how a **colleague** is told something, and none of that
 * machinery applies — nobody approves telling Emily that a task was assigned
 * to her.
 *
 * Sharing the enum would mean `in_app` appearing in a message template's
 * channel picker, and `sms` appearing here.
 *
 * ## `in_app` is not optional, and that is the design
 *
 * A person can turn off the email and the push for a type. They cannot turn
 * off the row: the panel is the record, and a notification nobody can find
 * later is ADR 0003's failure — every flow needs a second door, and for the
 * outbound channels the in-app list *is* the second door.
 */
enum NotificationChannel: string implements HasLabel
{
    use ProvidesOptions;

    /** The panel. Always written, never a preference. */
    case InApp = 'in_app';

    case Email = 'email';

    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In the app',
            self::Email => 'Email',
            self::Push => 'Push notification',
        };
    }

    /**
     * Whether somebody may switch this one off.
     *
     * Only `in_app` may not, for the reason above. Expressed as a method
     * rather than left to each caller because S78 and the fan-out both need
     * the answer and would otherwise each hold their own copy of it.
     */
    public function isOptional(): bool
    {
        return $this !== self::InApp;
    }

    /**
     * Whether this one wakes somebody up.
     *
     * The whole of what quiet hours are about. A row appearing in a panel
     * wakes nobody, so F12.4's *"nobody wants a 6am push"* is a rule about
     * these two and not about the notification itself — which is why the row
     * is written immediately and only the outbound channels are held.
     */
    public function reachesOut(): bool
    {
        return $this !== self::InApp;
    }

    /**
     * Same shape as {@see AutomationActionType::availableFrom()}, same reason.
     *
     * Offering a channel nothing can deliver on lets somebody set a preference
     * that silently does nothing — `CLAUDE.md`'s *"a row nothing can reach is
     * a rule nobody is following"* pointed the other way round.
     *
     * ## Push was gated on this string until #103, and now is gated on config
     *
     * The gate did not disappear when the sender arrived, it moved. Push needs
     * a VAPID key pair, and an environment without one can register no
     * subscriptions and deliver nothing — so *"is this build able to push"*
     * became *"is this **environment** configured to"*, which is a question
     * only the configuration can answer.
     *
     * That is why the answer is `SendPush::configured()` rather than a
     * constant: staging may have keys and a developer's laptop may not, and
     * offering the switch on the laptop would set a preference that silently
     * does nothing — the exact failure this method was written for.
     */
    public function availableFrom(): ?string
    {
        if ($this !== self::Push) {
            return null;
        }

        return SendPush::configured()
            ? null
            : 'Push notifications need VAPID keys configured for this environment.';
    }
}
