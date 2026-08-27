<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a push notification is sent (#103 · PRD §4.12 F12.2, §6.2).
 *
 * ## No `team_id`, and `passkeys` is the precedent
 *
 * `ModelTenancyConventionTest` records this as the exception it is. A push
 * subscription is a **credential for a browser** — an opaque endpoint URL
 * plus the two keys that authorise encrypting to it — and it belongs to a
 * human rather than to a tenancy, exactly like a passkey and for the same
 * reason: a person who works for two agencies has one phone and signs in
 * once. Giving it a `team_id` would mean a row per team per device, and a
 * send would then have to de-duplicate by endpoint or push the same sentence
 * to the same lock screen twice.
 *
 * What makes that safe here, and did not make it safe for `people` (ADR 0002,
 * *"the hole the layers do not cover"*): **this table holds no customer
 * data.** Every row describes a *colleague's own browser*. There is no client
 * name, address or figure in it, and there is nothing one team could learn
 * about another by reading it. The moment a column here described somebody a
 * team is working *with* rather than somebody working *for* them, it would
 * belong on a team-scoped table instead.
 *
 * Retention follows the person, which is what the cascade is for:
 * `records:purge` hard-deletes an account thirty days after deletion, and the
 * subscriptions go with it rather than needing their own sweep.
 *
 * ## No soft deletes, deliberately
 *
 * Most tables in this product soft-delete so PRD §9's thirty-day window has
 * something to restore. There is nothing to restore here. A subscription is
 * removed for one of two reasons — the push service answered 404 or 410,
 * meaning the endpoint is *gone*, or somebody switched notifications off on
 * that device — and in both cases the row is not recoverable state, it is a
 * dead address. Keeping it soft-deleted would mean a table of dead endpoints
 * that `SendPush` has to remember to filter, which is the kind of filter that
 * one day gets forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('person_id')
                ->constrained('people')
                ->cascadeOnDelete();

            /*
             * `text`, not `string`. A push endpoint is a URL chosen by the
             * browser vendor — FCM's are already ~180 characters and Mozilla's
             * carry a UUID path — and there is no specified maximum. A
             * `varchar(255)` here would be a truncation that presents as "push
             * silently stops working on one browser", which is the worst
             * possible symptom for a feature nobody can see.
             */
            $table->text('endpoint');

            /*
             * The two halves of RFC 8291's key agreement: the browser's public
             * key and the auth secret. Base64url as the browser hands them
             * over — decoded at send time rather than at rest, so what is
             * stored is exactly what `pushManager` produced.
             */
            $table->string('public_key');
            $table->string('auth_token');

            /*
             * Which device this is, so S55 can say "iPhone" rather than
             * offering somebody three identical rows. Nullable because a
             * browser may send nothing, and a subscription with no label is
             * still a working subscription.
             */
            $table->string('user_agent')->nullable();

            /*
             * For pruning. A subscription nothing has succeeded in reaching
             * for months is almost certainly a device that has been wiped —
             * the push service is not obliged to tell us, and many do not.
             */
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['person_id', 'last_seen_at']);
        });

        /*
         * One row per endpoint, and a **partial-free plain unique** because
         * nothing soft-deletes here.
         *
         * The endpoint is the identity: a browser that re-subscribes hands
         * back the same one, and `PushSubscriptionController` upserts on it.
         * Without this, every page load that re-registered would add a row and
         * a person would receive one push per visit they had ever made.
         *
         * Postgres cannot index a `text` column of unbounded length in a
         * btree beyond ~2700 bytes, which no real endpoint approaches; the
         * index is on the column itself rather than a hash so the upsert can
         * use it.
         */
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->unique('endpoint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
