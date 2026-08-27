<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How somebody wants to be told (F12.4 · Screen Inventory S78 · issue #101).
 *
 * F12.4 in one line: *"Channel and quiet hours per event type. **Nobody wants
 * a 6am push.**"*
 *
 * ## One row per person per team, not per type
 *
 * The obvious shape is (person × team × type), and it is wrong in a specific
 * way: a type added in a later slice would have **no row** for anybody, and
 * every read would have to decide what a missing row means. Answering that in
 * one place is fine; answering it in a schema where the absence is the common
 * case is how a new notification type ships switched off for everybody with
 * nobody noticing.
 *
 * So the defaults live in {@see App\Enums\NotificationType::defaultChannels()}
 * — in code, where a new case cannot be added without choosing — and this table
 * holds only what somebody has actually changed. A person with no row here has
 * the defaults, which is the correct answer for a new member and for a new
 * type alike.
 *
 * ## Quiet hours are a window, not a per-type setting
 *
 * F12.4's phrasing puts both *"per event type"*, and only one of them is. A
 * person has one evening; they do not have a different evening for task
 * assignments than for overrides. What **is** per type is whether a type may
 * cross the window at all, and that is a product decision rather than a
 * preference — `NotificationType::bypassesQuietHours()`, argued there.
 *
 * ## In the team's timezone (PRD §9)
 *
 * Stored as a wall-clock time with no date and no zone, and resolved against
 * `teams.timezone` at send time. A UTC instant would be wrong the day the team
 * crosses a daylight-saving boundary, and *"nine in the evening"* is what
 * somebody means when they set it — not *"20:00Z, which is nine until March"*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->productDefaults();

            $table->foreignUlid('person_id')->constrained('people')->cascadeOnDelete();

            /*
             * `{type => [channel, …]}`, holding only what was changed. An
             * empty object is a person who has looked at S78 and left it
             * alone, which is different from having no row at all only in that
             * it costs a row.
             */
            $table->json('channels')->nullable();

            /*
             * The window, in the team's wall clock. Both null or both set,
             * because half a window is a rule nothing can evaluate — enforced
             * by `SaveNotificationPreferences`' `required_with` pair and by
             * nothing else. **There is no CHECK constraint and no model hook**,
             * which an earlier version of this comment claimed: the guard is
             * one layer, and saying so is the difference between knowing where
             * to look and trusting a sentence.
             *
             * A window that wraps midnight (21:00 → 07:00) is the ordinary
             * case rather than the exception, which is why this is two times
             * and not a start plus a duration: the wrap is then something the
             * comparison handles rather than something the storage has to.
             */
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();

            // One per person per team, and the lookup the fan-out makes.
            $table->unique(['team_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
