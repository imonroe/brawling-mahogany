<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Logging\Redactor;
use Illuminate\Support\Facades\Log;

/**
 * An SNS envelope, and whether it is really from Amazon (#95 · PRD §8.5).
 *
 * Issue #95 names the risk in one line: *"an unauthenticated webhook that
 * writes suppression records is an obvious abuse target."* It is worse than
 * that, because suppression is the product's one **account-wide** write —
 * anybody who could post here unverified could stop this product writing to
 * any address they chose, for every team on the platform, permanently.
 *
 * ## What is actually checked, and why each part is not optional
 *
 * 1. **The certificate host.** `SigningCertURL` is a URL Amazon puts in the
 *    body, so it is attacker-controlled until it is checked: fetching whatever
 *    it names would let somebody sign with their own key and hand us the
 *    matching certificate. It must be `https` on `sns.<region>.amazonaws.com`
 *    (or the China and GovCloud partitions), matched against an anchored
 *    pattern — `str_contains($url, 'amazonaws.com')` passes
 *    `https://evil.test/?x=amazonaws.com`, which is how this is usually got
 *    wrong.
 * 2. **The signature, over the canonical string.** SNS signs a specific
 *    field-by-field encoding, and the fields differ between a `Notification`
 *    and a `SubscriptionConfirmation`. Signing the raw body instead — which is
 *    the intuitive thing — verifies nothing, because Amazon did not sign the
 *    raw body.
 * 3. **The topic.** A valid Amazon signature only proves *some* SNS topic sent
 *    this. Anybody with an AWS account can create a topic and point it here,
 *    and its notifications are as genuinely signed as ours. `SES_SNS_TOPIC_ARN` is
 *    what makes the check mean *our* topic.
 *
 * Dropping any one of the three leaves a hole that looks closed.
 */
final readonly class SnsMessage
{
    /**
     * The signing certificate's host, anchored.
     *
     * `sns.<region>.amazonaws.com` in the commercial partition, plus the two
     * that do not share that suffix. Anchored at both ends, which is the whole
     * point — an unanchored match is a bypass, not a laxity.
     */
    private const CERTIFICATE_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/D';

    /**
     * And the path, which AWS's own `MessageValidator` also constrains.
     *
     * The host check is what stops somebody serving us their own certificate;
     * this is smaller — it stops an arbitrary path on Amazon's own host being
     * fetched, so the endpoint cannot be turned into a general-purpose
     * outbound request even for one hop. Cheap, so there is no reason not to.
     */
    private const CERTIFICATE_PATH = '/\.pem$/D';

    /**
     * The fields SNS signs, per type and **in this order**.
     *
     * The canonical string is `key\nvalue\n` for each present field in this
     * sequence. The order is Amazon's, alphabetical by chance rather than by
     * rule, and getting it wrong produces a signature mismatch on every valid
     * message — which reads as *"the webhook is broken"* rather than as a bug
     * here.
     *
     * @var array<string, list<string>>
     */
    private const SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function __construct(public array $envelope, public string $type) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function tryFrom(array $envelope): ?self
    {
        $type = is_string($envelope['Type'] ?? null) ? $envelope['Type'] : '';

        return array_key_exists($type, self::SIGNED_FIELDS)
            ? new self($envelope, $type)
            : null;
    }

    public function topicArn(): ?string
    {
        return is_string($this->envelope['TopicArn'] ?? null) ? $this->envelope['TopicArn'] : null;
    }

    public function subscribeUrl(): ?string
    {
        return is_string($this->envelope['SubscribeURL'] ?? null) ? $this->envelope['SubscribeURL'] : null;
    }

    /**
     * The `Message` field, which for SES is itself a JSON document.
     *
     * @return array<string, mixed>|null
     */
    public function decoded(): ?array
    {
        $raw = $this->envelope['Message'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isNotification(): bool
    {
        return $this->type === 'Notification';
    }

    public function isSubscriptionConfirmation(): bool
    {
        return $this->type === 'SubscriptionConfirmation';
    }

    /**
     * Does Amazon's signature over the canonical string check out?
     *
     * @param  callable(string): (string|null)  $fetchCertificate
     */
    public function isAuthentic(callable $fetchCertificate): bool
    {
        $url = is_string($this->envelope['SigningCertURL'] ?? null)
            ? $this->envelope['SigningCertURL']
            : '';

        if (! $this->certificateUrlIsAmazons($url)) {
            $this->refuse('certificate_url');

            return false;
        }

        $signature = is_string($this->envelope['Signature'] ?? null)
            ? base64_decode($this->envelope['Signature'], true)
            : false;

        if ($signature === false || $signature === '') {
            $this->refuse('signature_missing');

            return false;
        }

        /*
         * SNS ships `1` (SHA1) and `2` (SHA256). SHA1 is accepted because
         * Amazon still sends it for older topics and refusing it would drop
         * genuine bounces, which is the failure mode this whole feature
         * exists to prevent — but it is named rather than defaulted, so
         * `SignatureVersion: 99` is a refusal rather than a silent SHA1.
         */
        $algorithm = match ($this->envelope['SignatureVersion'] ?? null) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };

        if ($algorithm === null) {
            $this->refuse('signature_version');

            return false;
        }

        $certificate = $fetchCertificate($url);

        if (! is_string($certificate) || $certificate === '') {
            $this->refuse('certificate_unavailable');

            return false;
        }

        $key = @openssl_pkey_get_public($certificate);

        if ($key === false) {
            $this->refuse('certificate_unreadable');

            return false;
        }

        $verified = openssl_verify($this->canonicalString(), $signature, $key, $algorithm) === 1;

        if (! $verified) {
            $this->refuse('signature_mismatch');
        }

        return $verified;
    }

    /**
     * The exact bytes Amazon signed.
     *
     * A field that is absent is **skipped**, not sent as empty — a
     * notification with no `Subject` signs six lines, not seven with a blank.
     * That single detail is the difference between this verifying every
     * message and verifying none of the ones without a subject, which is most
     * of them.
     */
    private function canonicalString(): string
    {
        $canonical = '';

        foreach (self::SIGNED_FIELDS[$this->type] as $field) {
            $value = $this->envelope[$field] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $canonical .= $field."\n".$value."\n";
        }

        return $canonical;
    }

    private function certificateUrlIsAmazons(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        return is_string($host)
            && preg_match(self::CERTIFICATE_HOST, mb_strtolower($host)) === 1
            && is_string($path)
            && preg_match(self::CERTIFICATE_PATH, mb_strtolower($path)) === 1;
    }

    /**
     * Say that something was refused, and never say what it contained.
     *
     * A rejected webhook body is attacker-controlled, and logging it verbatim
     * is how a log becomes a place to put things. `reason_code` rather than
     * `reason` for the reason `CLAUDE.md` records: `Redactor` redacts the
     * second and passes the first, so a diagnostic spelled the natural way
     * reaches the operator as `[redacted]`.
     */
    private function refuse(string $code): void
    {
        Log::warning('delivery.webhook_refused', Redactor::context([
            'reason_code' => $code,
            'sns_type' => $this->type,
        ]));
    }
}
