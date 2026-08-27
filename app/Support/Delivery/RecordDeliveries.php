<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Models\ActionInstance;
use App\Models\MessageDelivery;
use App\Support\Automation\SendDecision;
use Illuminate\Support\Carbon;

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
            $rows[] = $this->write($instance, $recipient, $channel, [
                'provider_message_id' => $providerMessageId,
                'status' => DeliveryStatus::Sent->value,
                'redirected' => $decision->redirected,
            ]);
        }

        /*
         * **And a row for each address that was dropped**, which is round 1 of
         * review's first blocker.
         *
         * Dropping a suppressed address and sending to the rest is right — one
         * dead mailbox must not silence a reachable client — but the first
         * version recorded the drop nowhere at all: the timeline named a
         * client who was never written to, the audit counted the survivors,
         * and S49 showed a "Goes to" listing two people above a delivery list
         * of one. A withheld copy is an **outcome**, and the row is where an
         * outcome lives.
         *
         * `noticed_at` is stamped, so S91's sweep tells the team. The bounce
         * that created the suppression may have been weeks ago, on another
         * deal, seen by somebody who has since left; *this* message not
         * reaching *this* client is new information.
         */
        foreach ($decision->withheld as $recipient) {
            $rows[] = $this->write($instance, $recipient, $channel, [
                // No provider id: nothing was handed over, so nothing will
                // ever come back about it.
                'provider_message_id' => null,
                'status' => DeliveryStatus::Suppressed->value,
                'withheld_reason' => $recipient['reason']->value,
                'noticed_at' => Carbon::now(),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array{name: string, email: string, membershipId: string|null}  $recipient
     * @param  array<string, mixed>  $attributes
     */
    private function write(
        ActionInstance $instance,
        array $recipient,
        MessageChannel $channel,
        array $attributes,
    ): MessageDelivery {
        $delivery = new MessageDelivery;

        $delivery->forceFill([
            'action_instance_id' => $instance->getKey(),
            /*
             * Null for anything raised before #95 threaded the id through the
             * payload. **Not** null for a sandbox redirect, which an earlier
             * version of this comment claimed: `SendRails::teamOwnerAddress()`
             * supplies the owner's membership, so the row points at the owner
             * — which is why `redirected` is a column rather than something a
             * reader has to infer from a name that does not match the message.
             *
             * The address is the fact either way; this is what lets a screen
             * link to somebody.
             */
            'team_membership_id' => $recipient['membershipId'] ?? null,
            'recipient_email' => $recipient['email'],
            'channel' => $channel->value,
            /*
             * `team_id` comes from the trait's resolved team, like every other
             * write in this product — and the instance is the team's, so the
             * two cannot disagree. Set explicitly all the same, because this
             * runs inside a queue worker where the resolved team is
             * `RunsForTeam`'s doing rather than a request's, and a class that
             * depends on that should say so.
             */
            'team_id' => $instance->team_id,
            ...$attributes,
        ])->save();

        return $delivery;
    }
}
