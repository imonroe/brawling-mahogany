<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * The Sentry end of the "no PII in logs, ever" rule (PRD §9).
 *
 * Sentry's Laravel integration subscribes to `MessageLogged`, which fires in
 * `Illuminate\Log\Logger::writeLog()` **before** the record reaches Monolog.
 * The Monolog processor therefore never sees it, and a breadcrumb would carry
 * the raw message and context to Sentry even though the log line itself was
 * redacted. These callbacks close that path, and the equivalent one on the
 * event payload.
 */
final class ScrubSentryEvents
{
    /**
     * Redact a breadcrumb before it is attached to an event.
     */
    public static function breadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $message === null ? null : Redactor::text($message),
            Redactor::context($breadcrumb->getMetadata()),
        );
    }

    /**
     * Redact an event before it is sent.
     *
     * Request bodies are already off (`max_request_body_size: none`); this
     * covers the message, the exception values, and the extra context.
     */
    public static function event(Event $event, ?EventHint $hint = null): Event
    {
        if ($event->getMessage() !== null) {
            $event->setMessage(
                Redactor::text($event->getMessage()),
                $event->getMessageParams(),
                $event->getMessageFormatted() === null
                    ? null
                    : Redactor::text($event->getMessageFormatted()),
            );
        }

        $event->setExtra(Redactor::context($event->getExtra()));

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $tags[$key] = Redactor::isSensitiveKey($key) ? Redactor::REDACTED : Redactor::text($value);
        }
        $event->setTags($tags);

        return $event;
    }
}
