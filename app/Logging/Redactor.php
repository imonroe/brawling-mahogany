<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * The redaction rules, in one place.
 *
 * PRD §9: "No PII in logs, ever." That rule has more than one exit from the
 * application — Monolog is one, and Sentry's breadcrumbs and event payloads
 * are others that never pass through Monolog at all. All of them call this.
 *
 * Two passes:
 *
 *  1. Keys whose *name* says the value is personal are replaced wholesale.
 *     Matching is by substring on the separator-stripped key, so
 *     `client_email`, `clientEmail`, `home_phone`, and `owner_name` are caught
 *     as well as the bare `email`, `phone`, and `name`.
 *  2. Anything that *looks* like an email address, a phone number, or a bank
 *     identifier is masked wherever it appears, because interpolated strings
 *     are how PII actually reaches a log.
 *
 * Redaction is deliberately blunt. A log line that is slightly less useful is
 * cheaper than a client's phone number sitting in a log aggregator.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /**
     * Key fragments that make a value personal. A key matches when one of
     * these appears anywhere in it once separators are stripped, so a
     * fragment here is deliberately specific: `apikey` rather than `key`,
     * because `key_dates` is a table in this product and belongs in a log.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_PARTS = [
        'password', 'secret', 'token', 'apikey', 'authorization', 'auth',
        'cookie', 'session', 'credential',
        'email', 'phone', 'mobile', 'fax',
        'name', 'firstname', 'lastname', 'surname',
        'address', 'street', 'city', 'postcode', 'postal', 'zip',
        'ssn', 'dob', 'birthdate', 'birthday',
        'account', 'routing', 'iban', 'card',
        'amount', 'price', 'value', 'salary', 'income',
        'body', 'contents', 'snippet', 'payload', 'note', 'notes', 'reason',
    ];

    /**
     * Keys that contain a sensitive fragment but are not personal data, and
     * are worth keeping because they are how a log stays useful.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'name_of_check', 'queue_name', 'job_name', 'class_name', 'channel_name',
        'connection_name', 'driver_name', 'command_name', 'event_name',
        'template_name', 'model_name', 'field_name', 'column_name',
        'exception', 'file', 'line', 'trace',
    ];

    private const PATTERNS = [
        // Email addresses.
        '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
        // North American phone numbers, with or without punctuation.
        '/(?<!\d)(?:\+1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/u',
        // Social security numbers.
        '/(?<!\d)\d{3}-\d{2}-\d{4}(?!\d)/u',
        // Bank account and routing numbers: any bare run of 9 to 17 digits.
        '/(?<!\d)\d{9,17}(?!\d)/u',
    ];

    /**
     * Redact an array of context, recursively.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function context(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = self::value($value);
        }

        return $scrubbed;
    }

    /**
     * Redact a single value of any type.
     */
    public static function value(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => self::context($value),
            is_string($value) => self::text($value),
            default => $value,
        };
    }

    /**
     * Mask anything that looks personal inside a string.
     */
    public static function text(string $value): string
    {
        return (string) preg_replace(self::PATTERNS, self::REDACTED, $value);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalised = mb_strtolower($key);

        if (in_array($normalised, self::ALLOWED_KEYS, true)) {
            return false;
        }

        /*
         * Match on the key with its separators removed, so `client_email`,
         * `clientEmail`, `emails`, and `api_key` all match while `deal_id`
         * and `key_dates` — the ones that make a log useful — do not.
         */
        $flattened = (string) preg_replace('/[^a-z0-9]/', '', $normalised);

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($flattened, $part)) {
                return true;
            }
        }

        return false;
    }
}
