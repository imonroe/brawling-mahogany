<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Logs\Log;

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
     * Request bodies are already off (`max_request_body_size: none`). This
     * covers the message, the exception values, the extra context, and the
     * tags.
     *
     * The exception value is the one that matters most in practice. Laravel
     * interpolates query bindings into `QueryException::getMessage()`, so a
     * failing lookup by email arrives here as
     * `select * from users where email = emily@example.com` — the most common
     * exception in the framework, carrying a client's address into a third
     * party.
     *
     * Not covered, deliberately and because they are separate options:
     * `before_send_transaction` (span descriptions) and
     * `before_send_check_in`. Neither is enabled; if one is turned on, it
     * needs its own callback here.
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

        $event->setExceptions(array_map(
            static function (ExceptionDataBag $exception): ExceptionDataBag {
                $exception->setValue(Redactor::text($exception->getValue()));

                return $exception;
            },
            $event->getExceptions(),
        ));

        $event->setExtra(Redactor::context($event->getExtra()));

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $tags[$key] = Redactor::isSensitiveKey($key) ? Redactor::REDACTED : Redactor::text($value);
        }
        $event->setTags($tags);

        return $event;
    }

    /**
     * Redact a Sentry Log record before it is sent.
     *
     * Sentry Logs are a different pipeline again: they bypass both
     * `before_breadcrumb` and `before_send`, and `LogsHandler::doWrite()`
     * pushes the raw message and context straight out. This is the only hook
     * they have.
     */
    public static function log(Log $log): Log
    {
        $log->setBody(Redactor::text($log->getBody()));

        foreach ($log->attributes()->toSimpleArray() as $key => $value) {
            $log->setAttribute(
                $key,
                Redactor::isSensitiveKey($key) ? Redactor::REDACTED : Redactor::value($value),
            );
        }

        return $log;
    }
}
