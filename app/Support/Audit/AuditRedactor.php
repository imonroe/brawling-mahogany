<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * PRD §9: *"No PII in logs, ever."*
 *
 * The `before`/`after` payloads are where an audit log leaks. "Changed
 * `phone`" is the fact the log exists to record; the number itself is not, and
 * writing it turns a security record into a copy of the client list with a
 * different retention policy.
 *
 * So sensitive attributes are reduced to the marker below. The audit entry
 * still proves *that* the field changed, *when*, and *by whom* — which is the
 * whole obligation — without becoming the thing it is meant to protect.
 */
final class AuditRedactor
{
    public const MARKER = '[redacted]';

    /**
     * Attribute names whose values never reach the log.
     *
     * Matched on the whole name and on a suffix, so `sending_identity_email`
     * and `client_phone` are caught alongside `email` and `phone`.
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'password',
        'email',
        'phone',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'token',
        'token_hash',
        'secret',
        'notes',
        'vendor_notes',
        'address',
        'street',
        'street_address',
        'ip',
        'signature_block',
    ];

    /**
     * @param  array<string, mixed>|null  $attributes
     * @return array<string, mixed>|null
     */
    public function redact(?array $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }

        $redacted = [];

        foreach ($attributes as $key => $value) {
            $redacted[$key] = $this->isSensitive((string) $key)
                ? self::MARKER
                : (is_array($value) ? $this->redact($value) : $value);
        }

        return $redacted;
    }

    public function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }
}
