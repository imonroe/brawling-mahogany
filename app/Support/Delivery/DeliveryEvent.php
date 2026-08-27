<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\SuppressionReason;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One thing SES says happened to one message (#95 · PRD §4.5 F5.8).
 *
 * Parsing is separated from applying so that the shape SES sends is understood
 * in exactly one place. Two shapes, in fact, and conflating them is the
 * commonest way this integration is got wrong: the older **SNS notification**
 * form keys on `notificationType` and carries `bounce`/`complaint`/`delivery`,
 * while **event publishing** keys on `eventType` and adds `Open`, `Click`,
 * `Reject` and more. An account can be configured either way — and one
 * configured for events sends `eventType` with no `notificationType` at all,
 * so a handler that reads only the first quietly records nothing while looking
 * completely healthy.
 */
final readonly class DeliveryEvent
{
    /**
     * @param  list<array{email: string, diagnostic: string|null}>  $recipients
     */
    private function __construct(
        public string $providerMessageId,
        public DeliveryStatus $status,
        public CarbonInterface $at,
        public array $recipients,
        public ?SuppressionReason $suppresses = null,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function tryFrom(array $message): ?self
    {
        $mail = is_array($message['mail'] ?? null) ? $message['mail'] : [];
        $id = is_string($mail['messageId'] ?? null) ? $mail['messageId'] : null;

        if ($id === null || $id === '') {
            return null;
        }

        // Either spelling, and `eventType` first because an account with event
        // publishing turned on sends both keys on some payloads.
        $type = is_string($message['eventType'] ?? null)
            ? $message['eventType']
            : (is_string($message['notificationType'] ?? null) ? $message['notificationType'] : '');

        return match ($type) {
            'Bounce' => self::bounce($id, $message),
            'Complaint' => self::complaint($id, $message),
            'Delivery' => self::delivery($id, $message),
            'Open' => self::opened($id, $message),
            /*
             * Everything else — `Send`, `Click`, `Reject`, `Rendering
             * Failure`, `DeliveryDelay`, `Subscription` — is understood and
             * deliberately ignored, which is different from unrecognised. The
             * caller returns 200 either way: an SNS endpoint that errors on a
             * payload it does not model is an endpoint SES retries forever and
             * eventually disables.
             */
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function bounce(string $id, array $message): self
    {
        $bounce = is_array($message['bounce'] ?? null) ? $message['bounce'] : [];

        /*
         * **Permanent only suppresses.** A `Transient` bounce is a full
         * mailbox or a greylist and the address is fine; suppressing on one
         * would cut a client off for a week because their inbox was full on
         * Tuesday. `Undetermined` is treated as transient for the same reason
         * — the failure this product must not have is a silently unreachable
         * client, and a repeat send costs far less than that.
         *
         * The delivery row still records the bounce in both cases, because a
         * team asking *"did the disclosure arrive"* needs the answer either
         * way.
         */
        $permanent = ($bounce['bounceType'] ?? null) === 'Permanent';

        return new self(
            providerMessageId: $id,
            status: DeliveryStatus::Bounced,
            at: self::timestamp($bounce['timestamp'] ?? null),
            recipients: self::recipients($bounce['bouncedRecipients'] ?? null),
            suppresses: $permanent ? SuppressionReason::HardBounce : null,
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function complaint(string $id, array $message): self
    {
        $complaint = is_array($message['complaint'] ?? null) ? $message['complaint'] : [];

        return new self(
            providerMessageId: $id,
            status: DeliveryStatus::Complained,
            at: self::timestamp($complaint['timestamp'] ?? null),
            recipients: self::recipients($complaint['complainedRecipients'] ?? null),
            /*
             * Always, and never conditionally. PRD §12.2 puts the complaint
             * threshold at **0.1%** — the level at which Amazon reviews the
             * account rather than the team — so continuing to write to
             * somebody who has reported you is spending every other team's
             * deliverability on one team's mailing list.
             */
            suppresses: SuppressionReason::Complaint,
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function delivery(string $id, array $message): self
    {
        $delivery = is_array($message['delivery'] ?? null) ? $message['delivery'] : [];

        return new self(
            providerMessageId: $id,
            status: DeliveryStatus::Delivered,
            at: self::timestamp($delivery['timestamp'] ?? null),
            /*
             * A Delivery notification lists plain addresses rather than
             * objects, which is the one place the two shapes differ enough to
             * matter.
             */
            recipients: self::recipients($delivery['recipients'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function opened(string $id, array $message): self
    {
        $open = is_array($message['open'] ?? null) ? $message['open'] : [];
        $mail = is_array($message['mail'] ?? null) ? $message['mail'] : [];

        /*
         * An Open names no recipient — a tracking pixel knows the message, not
         * which copy of it loaded — so it applies to the message's whole
         * destination list. On a message to one person that is exact; on a
         * message to three it means *somebody* opened it, and the screen must
         * not claim more. `DeliveryStatus::isEvidence()` carries the same
         * caution one layer along.
         */
        return new self(
            providerMessageId: $id,
            status: DeliveryStatus::Opened,
            at: self::timestamp($open['timestamp'] ?? null),
            recipients: self::recipients($mail['destination'] ?? null),
        );
    }

    /**
     * @return list<array{email: string, diagnostic: string|null}>
     */
    private static function recipients(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $rows[] = ['email' => $entry, 'diagnostic' => null];

                continue;
            }

            if (! is_array($entry) || ! is_string($entry['emailAddress'] ?? null)) {
                continue;
            }

            $rows[] = [
                'email' => $entry['emailAddress'],
                'diagnostic' => is_string($entry['diagnosticCode'] ?? null)
                    ? $entry['diagnosticCode']
                    : (is_string($entry['status'] ?? null) ? $entry['status'] : null),
            ];
        }

        return $rows;
    }

    private static function timestamp(mixed $raw): CarbonInterface
    {
        if (! is_string($raw) || $raw === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            /*
             * A malformed timestamp is not a reason to drop a bounce. The
             * fact matters far more than the minute it happened, and *"now"*
             * is off by however long the notification took to arrive rather
             * than by anything a person would notice.
             */
            return Carbon::now();
        }
    }
}
