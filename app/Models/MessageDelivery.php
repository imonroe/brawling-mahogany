<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Carbon\CarbonInterface;
use Database\Factories\MessageDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One copy of one message, and what became of it (#95 · PRD §4.5 F5.8).
 *
 * Team-scoped, unlike {@see SuppressedAddress} beside it, and the pair is
 * worth reading together: *what happened to our message* is a team's own
 * business, and *this address is dead* is the account's.
 *
 * @property string $id
 * @property string $team_id
 * @property string $action_instance_id
 * @property string|null $team_membership_id
 * @property string $recipient_email
 * @property MessageChannel $channel
 * @property string|null $provider_message_id
 * @property DeliveryStatus $status
 * @property Carbon|null $delivered_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $bounced_at
 * @property Carbon|null $complained_at
 * @property Carbon|null $noticed_at
 * @property string|null $detail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([])]
class MessageDelivery extends Model
{
    /** @use HasFactory<MessageDeliveryFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => DeliveryStatus::class,
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'noticed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ActionInstance, $this>
     */
    public function actionInstance(): BelongsTo
    {
        return $this->belongsTo(ActionInstance::class);
    }

    /**
     * @return BelongsTo<TeamMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TeamMembership::class, 'team_membership_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DeliveryStatus::Bounced->value,
            DeliveryStatus::Complained->value,
        ]);
    }

    /**
     * Move this row forward, and refuse to move it back.
     *
     * SNS delivers at least once and **in no guaranteed order**, so a Delivery
     * notification can land after an Open and a duplicate of either an hour
     * later. Ranking the statuses makes every replay a no-op without a ledger
     * of notification ids to keep — which is the same reasoning
     * `suppressed_addresses.email` being unique uses one table over.
     *
     * The timestamps are still written on the way past, because they are not
     * exclusive: a message can be delivered to the server and bounce off the
     * mailbox afterwards, and both facts are true.
     */
    public function advanceTo(DeliveryStatus $status, ?CarbonInterface $at = null, ?string $detail = null): bool
    {
        $at ??= Carbon::now();

        $column = match ($status) {
            DeliveryStatus::Delivered => 'delivered_at',
            DeliveryStatus::Opened => 'opened_at',
            DeliveryStatus::Bounced => 'bounced_at',
            DeliveryStatus::Complained => 'complained_at',
            DeliveryStatus::Sent => null,
        };

        $changes = [];

        if ($column !== null && $this->{$column} === null) {
            $changes[$column] = $at;
        }

        if ($status->rank() > $this->status->rank()) {
            $changes['status'] = $status->value;

            /*
             * The detail belongs to the status it arrived with. A bounce
             * reason must not be overwritten by the "delivered to the server"
             * note that a later, out-of-order notification carries — which is
             * the same failure `SendRails` records about writing a rail's
             * refusal over somebody's cancellation.
             */
            if ($detail !== null) {
                $changes['detail'] = $detail;
            }

            /*
             * Stamped **once**, on the way into a failure, with this
             * product's own clock rather than Amazon's. The migration argues
             * why: the alert sweep windows on this column, and a notification
             * that took twenty minutes to arrive would otherwise land behind
             * the mark and be reported to nobody.
             */
            if ($status->isFailure() && $this->noticed_at === null) {
                $changes['noticed_at'] = Carbon::now();
            }
        }

        if ($changes === []) {
            return false;
        }

        $this->forceFill($changes)->save();

        return true;
    }
}
