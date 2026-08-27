<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Models\ActionInstance;
use App\Models\MessageDelivery;
use App\Support\Automation\SendDecision;

/**
 * The row that lets somebody ask *"did it arrive"* (#95 · PRD §4.5 F5.8).
 *
 * Written at the moment of a successful hand-off, one per address, and never
 * before: a delivery row for a message the transport rejected would be a
 * record of something that did not happen, and `action_instances.error`
 * already carries that case.
 *
 * ## Why the provider's id is written here and not only on the instance
 *
 * `action_instances.provider_message_id` is one column and a message can go to
 * three people; SES assigns **one** id to the message it accepted, so all
 * three rows carry the same one and the webhook fans out across them. That is
 * exactly right for a bounce, which names the message and then lists which
 * recipients it bounced for.
 */
final class RecordDeliveries
{
    /**
     * @return list<MessageDelivery>
     */
    public function forSend(
        ActionInstance $instance,
        SendDecision $decision,
        ?string $providerMessageId,
    ): array {
        $channel = $instance->messageTemplate->channel ?? MessageChannel::Email;

        $rows = [];

        foreach ($decision->recipients as $recipient) {
            $delivery = new MessageDelivery;

            $delivery->forceFill([
                'action_instance_id' => $instance->getKey(),
                /*
                 * Null for a sandbox redirect and for anything raised before
                 * #95 threaded the id through the payload. The address below
                 * is the fact; this is only what lets a screen link to
                 * somebody.
                 */
                'team_membership_id' => $recipient['membershipId'] ?? null,
                'recipient_email' => $recipient['email'],
                'channel' => $channel->value,
                'provider_message_id' => $providerMessageId,
                'status' => DeliveryStatus::Sent->value,
            ]);

            /*
             * `team_id` comes from the trait's resolved team, like every other
             * write in this product — and the instance is the team's, so the
             * two cannot disagree. Set explicitly all the same, because this
             * runs inside a queue worker where the resolved team is
             * `RunsForTeam`'s doing rather than a request's, and a class that
             * depends on that should say so.
             */
            $delivery->forceFill(['team_id' => $instance->team_id])->save();

            $rows[] = $delivery;
        }

        return $rows;
    }
}
