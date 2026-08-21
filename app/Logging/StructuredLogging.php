<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * A Monolog tap: JSON to stdout, with PII stripped on the way out.
 *
 * JSON because the container platform has to be able to do something with a
 * log line beyond storing it, and stdout because a file inside a container is
 * a log nobody reads (PRD §9, Observability).
 */
final class StructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactPii);

        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter(new JsonFormatter);
            }
        }
    }
}
