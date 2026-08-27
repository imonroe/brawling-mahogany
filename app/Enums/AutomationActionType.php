<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What an automation does when it fires (PRD §4.5 F5.3 · issue #91).
 *
 * F5.3's v1 list, unchanged: send email, create task, create calendar event,
 * post internal notification, send push notification, prompt a manual action.
 *
 * ## Which of them need a message template
 *
 * Three do, and the shape of the editor depends on knowing which: an email or
 * a push carries words somebody wrote, and a task or a calendar event carries
 * a title the automation itself supplies. `needsMessageTemplate()` is what
 * stops S44 asking for a template on a *create task* and then storing a
 * pointer nothing reads.
 *
 * ## Availability, same rule as {@see AutomationTrigger}
 *
 * A calendar event needs the calendar (Slice 4); a notification and a push
 * need `notifications` and `push_subscriptions` (#101, #103). Each is a case
 * so a later slice does not have to migrate stored rows, and none of them is
 * offerable until the thing that executes it exists.
 */
enum AutomationActionType: string implements HasLabel
{
    use ProvidesOptions;

    case SendEmail = 'send_email';
    case CreateTask = 'create_task';
    case ManualPrompt = 'manual_prompt';
    case PostInternalNotification = 'post_internal_notification';
    case SendPushNotification = 'send_push_notification';
    case CreateCalendarEvent = 'create_calendar_event';

    public function label(): string
    {
        return match ($this) {
            self::SendEmail => 'Send an email',
            self::CreateTask => 'Create a task',
            self::ManualPrompt => 'Prompt somebody to do it',
            self::PostInternalNotification => 'Post a notification to the team',
            self::SendPushNotification => 'Send a push notification',
            self::CreateCalendarEvent => 'Create a calendar event',
        };
    }

    public function availableFrom(): ?string
    {
        return match ($this) {
            self::SendPushNotification => 'Web push arrives later in Slice 3 (#103).',
            self::CreateCalendarEvent => 'The calendar arrives in Slice 4.',
            default => null,
        };
    }

    public function isAvailable(): bool
    {
        return $this->availableFrom() === null;
    }

    /** Whether this action sends words somebody wrote, from a template. */
    public function needsMessageTemplate(): bool
    {
        return match ($this) {
            self::SendEmail, self::SendPushNotification, self::PostInternalNotification => true,
            default => false,
        };
    }

    /**
     * The channel a template must be on to be used by this action.
     *
     * Null when the action needs no template. An email action pointed at a
     * push template would render an HTML body onto a lock screen, so the pair
     * is checked rather than assumed.
     */
    public function channel(): ?MessageChannel
    {
        return match ($this) {
            self::SendEmail => MessageChannel::Email,
            self::SendPushNotification, self::PostInternalNotification => MessageChannel::Push,
            default => null,
        };
    }

    /**
     * Whether the action is presented to a human rather than fired (F5.4).
     *
     * F5.4 wants both *"recorded identically once done"*, so this is about
     * how the instance starts and not about how it is written down.
     */
    public function isManual(): bool
    {
        return $this === self::ManualPrompt;
    }

    /**
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->isAvailable()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
