<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * A Monolog tap that installs {@see RedactPii} without changing the format.
 *
 * Every channel the application can write to gets this. The rule is "no PII in
 * logs, ever" (PRD §9) — not "no PII in the production log channel".
 */
final class ScrubPii
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactPii);
    }
}
