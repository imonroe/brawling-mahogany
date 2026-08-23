<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * The Monolog end of the "no PII in logs, ever" rule (PRD §9).
 *
 * The rules themselves live in {@see Redactor}, because Monolog is not the
 * only way data leaves this application — Sentry's breadcrumbs and events do
 * not pass through Monolog at all, and they call the same redactor.
 */
final class RedactPii implements ProcessorInterface
{
    public const REDACTED = Redactor::REDACTED;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record
            ->with(message: Redactor::text($record->message))
            ->with(context: Redactor::context($record->context))
            ->with(extra: Redactor::context($record->extra));
    }
}
