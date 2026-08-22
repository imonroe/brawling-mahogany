<?php

declare(strict_types=1);

namespace App\Support\Import;

use RuntimeException;

/**
 * A reviewed row whose choice cannot be carried out (Screen Inventory S33).
 *
 * Distinct from a crash so the failure report can say something useful. The
 * message is shown to the person who ran the import, so it names what to do
 * next rather than what went wrong internally — and never quotes the value,
 * which would put a client's address in a JSONB column and then in Sentry
 * (PRD §9: no PII in logs).
 */
final class ImportRowRefused extends RuntimeException {}
