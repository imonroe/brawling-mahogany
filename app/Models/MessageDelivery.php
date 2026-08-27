<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Enums\SuppressionReason;
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
 * @property SuppressionReason|null $withheld_reason
 * @property bool $redirected
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
            'withheld_reason' => SuppressionReason::class,
            'redirected' => 'boolean',
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
        /*
         * Derived from the enum, never listed again here. Round 1 of review
         * arrived with a third failure case (`suppressed`) and this scope
         * would have gone on counting two — which is how a delivery stops
         * being seen by the sweep that exists to see it.
         */
        return $query->whereIn('status', DeliveryStatus::failureValues());
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
            /*
             * Neither has a timestamp of its own. `sent` is the row's starting
             * state, and `suppressed` is written whole by
             * `RecordDeliveries` — nothing can advance *into* it, because
             * nothing was handed to a provider for a notification to name.
             */
            DeliveryStatus::Sent, DeliveryStatus::Suppressed => null,
        };

        $changes = [];

        if ($column !== null && $this->{$column} === null) {
            $changes[$column] = $at;
        }

        $advancing = $status->rank() > $this->status->rank();

        if ($advancing) {
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
             * Stamped on **each escalation** into a failure, with this
             * product's own clock rather than Amazon's.
             *
             * The migration argues the clock: the alert sweep windows on this
             * column, and a notification that took twenty minutes to arrive
             * would otherwise land behind the mark and be reported to nobody.
             *
             * Round 1 of review found the *once* half wrong. Written only when
             * null, a bounce already reported and marked would swallow a
             * later complaint on the same row — the escalation the team most
             * needs to hear about, silenced by the record of the smaller
             * problem. It is safe to re-stamp precisely because it is inside
             * `$advancing`: a replay does not advance, so it does not stamp.
             */
            if ($status->isFailure()) {
                $changes['noticed_at'] = Carbon::now();
            }
        }

        if ($changes === []) {
            return false;
        }

        /*
         * **A conditional UPDATE, not a read-then-write.**
         *
         * Round 1 of review: SNS delivers at least once, so two copies of one
         * bounce can land on two web workers at the same moment. Both read a
         * row still marked `sent`, both decided they had changed it, and the
         * deal got **two** `message.bounced` entries. The unique index
         * protected the suppression; nothing protected the timeline.
         *
         * `WHERE status = <what we read>` makes the database decide which of
         * them owns the transition, in one statement, with no window between
         * the check and the write — the same shape `ExecuteAction` uses to
         * claim `message_key`. The loser sees `false` and stands down, which
         * is the answer a replay gets too.
         *
         * Guarded on `status` alone rather than on every column: it is the one
         * that gates the timeline entry and the suppression, and widening the
         * predicate to include the timestamps would make a pure timestamp
         * backfill lose a race it has no reason to be in.
         */
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', $this->status->value)
            ->update([...$changes, 'updated_at' => Carbon::now()]);

        if ($claimed === 0) {
            return false;
        }

        $this->forceFill($changes)->syncOriginal();

        return true;
    }
}
