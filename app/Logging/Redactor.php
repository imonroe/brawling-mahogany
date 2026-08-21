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
 * Keys are decided in three passes, in this order: fragments that may never
 * be logged whatever they are called, then the allowlist of the product's own
 * vocabulary, then the general block list. The order is the load-bearing part
 * — the allowlist is more powerful than the block list, so it must not be able
 * to reach a credential.
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
     * Fragments that no allowlist may override.
     *
     * The allowlist below is evaluated first, which makes it strictly more
     * powerful than the block list — so the things that must never be logged
     * whatever they are suffixed with are checked before either. `session_id`,
     * `password_reset_code`, `token_id`, and `zip_code` all end in an
     * innocuous suffix and are none of them innocuous.
     *
     * @var list<string>
     */
    private const NEVER_LOGGED_PARTS = [
        'password', 'secret', 'token', 'credential', 'session', 'cookie',
        'authorization', 'auth', 'apikey', 'privatekey', 'signature',
        'ssn', 'dob', 'birth',
        'account', 'routing', 'iban', 'card', 'cvv',
        'zip', 'postal', 'postcode',
    ];

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
        'body', 'contents', 'snippet', 'payload', 'note', 'notes',
        // Free text a person typed — an override reason routinely quotes a
        // client. Log a `reason_code` instead when the value is enumerated.
        'reason',
    ];

    /**
     * Keys that contain a sensitive fragment but are not personal data.
     *
     * Over-redaction has a cost of its own: a log with nothing identifying in
     * it cannot be followed, and the block list always wins, so the exceptions
     * have to be deliberate. Patterns rather than exact strings, because the
     * product's own vocabulary generates families of them.
     *
     * Note which `*_name` keys are **not** here: `deal_name` is a street
     * address (IA §10) and `person_name`, `client_name`, `property_name`, and
     * `team_name` are all somebody's name. Those stay redacted.
     *
     * @var list<string>
     */
    private const ALLOWED_KEY_PATTERNS = [
        // The process vocabulary: none of these is a person or a place.
        '/^(stage|workflow|task|gate|action|automation|template|pack|milestone|trigger)_(name|label|type|state|status|key)$/',
        // Infrastructure.
        '/^(job|queue|class|channel|connection|driver|command|event|handler|listener|mailer|disk|store)_(name|type|class)$/',
        /*
         * Identifiers, counts, and timestamps are how a log line stays
         * followable. Deliberately broad on the suffix and narrow on nothing
         * else — which is safe only because NEVER_LOGGED_PARTS is checked
         * first: `session_id` and `password_reset_code` end here too.
         */
        '/_(id|ids|type|types|code|codes|count|state|status|version|at)$/',
        // Words that merely contain a sensitive fragment by accident:
        // "namespace" holds "name", "author" holds "auth", "capacity" "city".
        '/^(namespace|author|capacity|filename_extension|placeholder)$/',
        '/^(exception|file|line|trace|level|message_id|duration_ms|attempts)$/',
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
        $flattened = (string) preg_replace('/[^a-z0-9]/', '', $normalised);

        // Checked before the allowlist, because the allowlist wins everything
        // it matches and a suffix must never be able to launder a credential.
        foreach (self::NEVER_LOGGED_PARTS as $part) {
            if (str_contains($flattened, $part)) {
                return true;
            }
        }

        foreach (self::ALLOWED_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return false;
            }
        }

        /*
         * Match on the key with its separators removed, so `client_email`,
         * `clientEmail`, `emails`, and `api_key` all match while `deal_id`
         * and `key_dates` — the ones that make a log useful — do not.
         */
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($flattened, $part)) {
                return true;
            }
        }

        return false;
    }
}
