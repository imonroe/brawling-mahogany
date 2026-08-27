<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\SuppressionReason;
use App\Logging\Redactor;
use App\Models\SuppressedAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only writer of `suppressed_addresses` (#95 · PRD §4.5 F5.8, §12.2).
 *
 * One writer, like `RecordActivity` and `AuditLogger` before it, and for a
 * sharper reason than either: this table is the one deliberately cross-tenant
 * record in the product, and a second writer is a second place for somebody to
 * forget that a row here binds **every** team.
 *
 * ## Recorded, never re-recorded
 *
 * SNS delivers at least once. The unique index on `email` is what makes the
 * second and third copies of a bounce harmless, and `upsert` is what turns the
 * resulting integrity error into a no-op — deliberately, rather than by
 * catching one. A repeat never rewrites the reason: an address suppressed for
 * a **complaint** and later hard-bouncing stays recorded as a complaint,
 * because that is the more serious fact and the one that governs what a person
 * is told.
 */
final class Suppression
{
    /**
     * Suppress an address, or leave a stronger existing reason alone.
     *
     * Returns whether this call is what put the row there — the caller uses it
     * to decide whether anybody needs telling, so that a replayed notification
     * does not produce a second alert about the same bounce.
     */
    public function record(
        string $email,
        SuppressionReason $reason,
        ?string $detail = null,
        ?string $discoveredByTeamId = null,
    ): bool {
        $email = SuppressedAddress::normalise($email);

        if ($email === '') {
            return false;
        }

        /*
         * **`withTrashed()`**, because a lift is a soft delete (round 2 of
         * review) and the unique index below covers trashed rows too. Looking
         * only at live rows would find nothing, fall through to the insert,
         * collide silently, and leave an address that has just hard-bounced
         * unsuppressed — the exact failure this class exists to prevent,
         * reached through the door that was opened to make lifting auditable.
         */
        $existing = SuppressedAddress::withTrashed()->where('email', $email)->first();

        if ($existing instanceof SuppressedAddress && $existing->trashed()) {
            /*
             * Lifted, and now bouncing again. Restored rather than inserted
             * afresh so the id an audit entry already points at stays the id
             * of this address's record — the history of a lift and a
             * re-suppression is one row's story, not two.
             */
            $existing->restore();

            $existing->forceFill([
                'reason' => $reason->value,
                'detail' => $detail,
                'discovered_by_team_id' => $discoveredByTeamId,
                'suppressed_at' => Carbon::now(),
            ])->save();

            $this->announce($reason, upgraded: false);

            return true;
        }

        if ($existing instanceof SuppressedAddress) {
            /*
             * Already known. A complaint outranks a bounce and nothing
             * outranks a complaint, so the only upgrade worth making is
             * *bounce → complaint*: somebody who reported you as spam is a
             * different problem from somebody whose mailbox is gone, and the
             * words the screen leads with differ.
             */
            if ($reason->threatensTheAccount() && ! $existing->reason->threatensTheAccount()) {
                $existing->forceFill([
                    'reason' => $reason->value,
                    'detail' => $detail,
                    'suppressed_at' => Carbon::now(),
                ])->save();

                $this->announce($reason, upgraded: true);

                return true;
            }

            return false;
        }

        /*
         * `insertOrIgnore` rather than `create`, because two SNS deliveries of
         * the same bounce can be in flight at once and the read above is not a
         * lock. Losing that race is the correct outcome — the row exists
         * either way — and it must not surface as a 500 on a webhook the
         * provider will simply retry.
         */
        $inserted = DB::table('suppressed_addresses')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'email' => $email,
            'reason' => $reason->value,
            'detail' => $detail,
            'discovered_by_team_id' => $discoveredByTeamId,
            'suppressed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        if ($inserted === 0) {
            /*
             * Lost the race, or collided with a soft-deleted row this call
             * read before another one restored it. Either way the answer is
             * the same: somebody else owns the row now, and *"already
             * suppressed"* is the correct outcome of the second attempt.
             */
            return false;
        }

        $this->announce($reason, upgraded: false);

        return true;
    }

    /**
     * Lift a suppression — the platform console only.
     *
     * Deliberately not something a team can do. An address gets here because a
     * mail server said it does not exist or a person reported the account for
     * spam, and *"try again anyway"* is a decision about the whole account's
     * standing with Amazon (PRD §12.2), not about one team's message.
     */
    public function lift(string $email): bool
    {
        $row = SuppressedAddress::query()
            ->where('email', SuppressedAddress::normalise($email))
            ->first();

        if (! $row instanceof SuppressedAddress) {
            return false;
        }

        /*
         * A **soft** delete, so the row survives the audit entry written about
         * it. The migration argues it; the short version is that an entry
         * whose `auditable_id` resolves to nothing cannot answer the question
         * it exists to answer.
         */
        return (bool) $row->delete();
    }

    /**
     * A line for whoever runs the platform, carrying no address.
     *
     * PRD §9: no PII in logs, ever — and an email address is the most
     * identifying thing in this whole feature. The count is on the table for
     * anybody who needs it; what the log is for is *"the complaint rate is
     * moving"*, which needs a `reason_code` and a timestamp and nothing else.
     *
     * `reason_code`, not `reason`: `Redactor::SENSITIVE_KEY_PARTS` holds
     * `reason` and `ALLOWED_KEY_PATTERNS` passes `_code$`, so the honest
     * spelling is the one that survives to the operator.
     */
    private function announce(SuppressionReason $reason, bool $upgraded): void
    {
        Log::warning('delivery.address_suppressed', Redactor::context([
            'reason_code' => $reason->value,
            /*
             * `escalation_code`, and the spelling is not cosmetic. The obvious
             * name for this was `threatens_account` — which
             * `Redactor::SENSITIVE_KEY_PARTS` matches, so it reached the
             * operator as `[redacted]` while every test still passed. Same
             * trap `CLAUDE.md` records about `reason`, one key along:
             * `ALLOWED_KEY_PATTERNS` passes `_code$`, so that is the ending a
             * diagnostic has to have to survive.
             */
            'escalation_code' => $reason->threatensTheAccount() ? 'account' : 'team',
            'upgraded' => $upgraded,
        ]));
    }
}
