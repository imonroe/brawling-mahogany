<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\SuppressionReason;
use App\Logging\Redactor;
use App\Models\SuppressedAddress;
use Carbon\CarbonInterface;
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
        ?CarbonInterface $occurredAt = null,
    ): bool {
        /*
         * When the provider says this happened, which is what decides whether
         * it can undo a lift. Defaults to now for a caller with no event
         * behind it — the console, which is a person acting deliberately in
         * the present.
         */
        $occurredAt ??= Carbon::now();

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
             * Lifted, and now bouncing again — **if the bounce is actually
             * newer than the lift.**
             *
             * Round 3 of review: the first version restored on *any* bounce,
             * and SNS retries a failed delivery for up to 23 days. So a
             * replayed copy of the notification that caused the suppression
             * silently reversed the operator's decision, and — because
             * `ApplyDeliveryEvent` reads a `true` from here as *"something new
             * about this address"* — wrote the deal a second `message.bounced`
             * entry, which is round 1's duplicate arriving through the fix for
             * round 2's.
             *
             * The sequence is the natural one rather than a coincidence: an
             * operator lifts **because** of the bounce, so the lift lands
             * inside the provider's retry window rather than years later. And
             * the reversal was invisible — the same log line, the same console
             * output, and an audit log holding `mail.suppression_lifted` with
             * nothing after it.
             *
             * Comparing the provider's own timestamp against `deleted_at` is
             * the discriminator, because the question really is *"did this
             * happen after somebody decided the address was fine"*. A bounce
             * that predates the lift is the event the operator already
             * considered.
             */
            $liftedAt = $existing->deleted_at;

            /*
             * **Unless the event outranks what is recorded**, which round 4 of
             * review found the gate refusing.
             *
             * The gate above is right about the case it was written for — a
             * *replay* of the notification the operator lifted in response to
             * — and wrong about an event of a different **kind**. A complaint
             * the operator has never seen is not "the event they already
             * considered": it is the one fact this feature treats as
             * non-negotiable, and `DeliveryEvent::complaint()` says so in as
             * many words.
             *
             * And it arrives late by nature. While an address is suppressed
             * nothing goes out, so any complaint in flight is necessarily
             * *about a message sent before the suppression* — and feedback
             * loops run minutes to hours behind the ISP timestamp they carry.
             * So the complaint's own clock predates the lift essentially
             * always, and the gate refused essentially always: measured, the
             * address stayed writable, the `message.complained` entry lost its
             * "nothing further will be sent" clause, and the next automated
             * message went out to the person who had reported the team.
             *
             * PRD §12.2 measures complaints **per account** at 0.1%, so that
             * is every tenant's deliverability, which is the whole argument
             * for this table having no `team_id`.
             *
             * The same escalation clause the live branch uses, so the two
             * agree: a hard bounce replayed against a hard-bounce row does not
             * outrank it, and neither does a complaint against a complaint.
             * Only the categorically-new fact gets through.
             */
            $escalates = $reason->threatensTheAccount()
                && ! $existing->reason->threatensTheAccount();

            if (! $escalates && $liftedAt instanceof CarbonInterface && ! $occurredAt->greaterThan($liftedAt)) {
                return false;
            }

            /*
             * Restored rather than inserted afresh so the id an audit entry
             * already points at stays the id of this address's record — the
             * history of a lift and a re-suppression is one row's story, not
             * two.
             *
             * **And the stronger reason survives**, which the first version
             * overwrote unconditionally: complaint → lift → hard bounce left
             * the row reading `hard_bounce`, so a team was told *"their mail
             * server said this address does not exist, check it with them"*
             * about somebody who had reported them for spam, and the complaint
             * vanished from exactly the history the soft delete was added to
             * preserve. Same precedence as the live branch below — the class
             * docblock states it as an invariant and it has to hold on both
             * paths.
             */
            $keepExisting = $existing->reason->threatensTheAccount()
                && ! $reason->threatensTheAccount();

            $existing->restore();

            /*
             * **The reason and the words beside it move together**, which
             * round 4 of review caught as a half-fix one column along: round 3
             * kept the stronger `reason` and overwrote `detail` and
             * `discovered_by_team_id` unconditionally, so an operator's row
             * read *"Marked as spam"* over *"smtp; 550 5.1.1 user unknown"*.
             * The team-facing sentence was right — `explanation()` reads the
             * reason — and the row contradicted itself.
             *
             * `CLAUDE.md`'s own rule about tones, one domain over: a thing
             * made of several fields moves as a whole or not at all.
             */
            $existing->forceFill($keepExisting ? [
                'suppressed_at' => Carbon::now(),
            ] : [
                'reason' => $reason->value,
                'detail' => $detail,
                'discovered_by_team_id' => $discoveredByTeamId,
                'suppressed_at' => Carbon::now(),
            ])->save();

            $keep = $keepExisting ? $existing->reason : $reason;

            $this->announce($keep, 'restored');

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

                $this->announce($reason, 'upgraded');

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

        $this->announce($reason, 'suppressed');

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
    private function announce(SuppressionReason $reason, string $event): void
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
            /*
             * Which of the three this was, rather than a boolean that could
             * only say *"upgraded"*. Round 3 of review: a **restore** reverses
             * an operator's lift, and it was emitting a line indistinguishable
             * from a first suppression — so the one event an operator would
             * want to find looked like the most ordinary one in the file.
             */
            'event_code' => $event,
        ]));
    }
}
