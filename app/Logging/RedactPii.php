<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * "No PII in logs, ever." — PRD §9.
 *
 * That rule cannot be honoured by memory across ninety issues, so it is
 * enforced here, at the last point before a line is written. Two passes:
 *
 *  1. Context keys whose *name* says the value is personal are replaced
 *     wholesale. `email`, `phone`, `address`, `first_name`, and the financial
 *     figures are all in scope.
 *  2. Anything in the message or in a surviving value that *looks* like an
 *     email address, a phone number, or a bank identifier is masked, because
 *     interpolated strings are how PII actually reaches a log.
 *
 * Redaction is deliberately blunt. A log line that is slightly less useful is
 * cheaper than a client's phone number sitting in a log aggregator.
 */
final class RedactPii implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    /**
     * Context keys that are never logged, matched case-insensitively against
     * the whole key.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password',
        'secret', 'token', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'authorization', 'auth', 'cookie', 'session',
        'two_factor_secret', 'two_factor_recovery_codes',
        'email', 'email_address', 'phone', 'phone_number', 'mobile',
        'name', 'first_name', 'last_name', 'full_name', 'client_name',
        'address', 'street', 'street_address', 'address_line_1', 'address_line_2',
        'city', 'postal_code', 'zip', 'zip_code',
        'ssn', 'dob', 'date_of_birth',
        'account_number', 'routing_number', 'iban', 'card_number',
        'transaction_value', 'purchase_price', 'earnest_money', 'amount',
        'body', 'body_html', 'body_text', 'contents', 'file_contents',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record
            ->with(message: $this->maskPatterns($record->message))
            ->with(context: $this->scrub($record->context))
            ->with(extra: $this->scrub($record->extra));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrub(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = match (true) {
                is_array($value) => $this->scrub($value),
                is_string($value) => $this->maskPatterns($value),
                default => $value,
            };
        }

        return $scrubbed;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(mb_strtolower($key), self::SENSITIVE_KEYS, true);
    }

    private function maskPatterns(string $value): string
    {
        $patterns = [
            // Email addresses.
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
            // North American phone numbers, with or without punctuation.
            '/(?<!\d)(?:\+1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/u',
            // Social security numbers.
            '/(?<!\d)\d{3}-\d{2}-\d{4}(?!\d)/u',
            // Bank account and routing numbers: any bare run of 9 to 17 digits.
            '/(?<!\d)\d{9,17}(?!\d)/u',
        ];

        return (string) preg_replace($patterns, self::REDACTED, $value);
    }
}
