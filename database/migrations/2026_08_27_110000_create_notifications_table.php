<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person has been told (PRD §4.12 F12.4 · Screen Inventory S08 · #101).
 *
 * ## `team_id`, and why it is not just the convention
 *
 * Every business table carries one. This one carries it for a second reason
 * issue #101 names: *"a person in two teams needs to know which one a
 * notification came from, and switching teams should not hide it."* The first
 * half is why it is on the row; the second is a rule about the **panel**, which
 * reads across teams on purpose and is the one screen in the product that does.
 * The global scope still applies to every other read.
 *
 * ## The row is written immediately; only the outbound channels wait
 *
 * F12.4's quiet hours are *"nobody wants a 6am push"* — a rule about being
 * woken, and a row appearing in a panel wakes nobody. So the notification is
 * recorded the moment it happens, and `deliver_after` holds the **email and
 * the push** until the window opens.
 *
 * That split is what makes *"delayed, not dropped"* true without a second
 * table: the record already exists, and what is deferred is a send. It also
 * means a person who opens the app at 7am has already been told, which is the
 * behaviour anybody would expect and the one a naive reading of quiet hours
 * would have broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * A `people` row, not a membership — this is the one table where
             * that is right rather than the mistake #140 corrected.
             *
             * A notification belongs to whoever signs in: the panel is per
             * login and reads across every team that person is in, so keying
             * it on a membership would mean the same human's notifications
             * living under two ids that nothing joins. `team_id` beside it is
             * what says which team it is about.
             *
             * `people` carries no `team_id`, so this cannot be half of a
             * composite key — the same trade `tasks.assignee_id` makes, and
             * for the same reason.
             */
            $table->foreignUlid('person_id')->constrained('people')->cascadeOnDelete();

            $table->string('type');

            /*
             * Where it belongs, when it belongs anywhere. Nullable because
             * F5.3's announcement can be about a workflow rather than a deal,
             * and because an automation failure is a fact about the team.
             *
             * `teamScopedForeign`, so a notification cannot point at another
             * team's deal even though the person it is for spans teams.
             */
            $table->teamScopedForeign('deal_id', 'deals', nullable: true);

            /*
             * The sentence, and everything the panel needs to draw a line
             * without opening anything. Rendered at raise time for the reason
             * `action_instances.payload` is: what a screen shows about
             * something that happened is a snapshot of the moment, and a task
             * renamed afterwards does not rewrite the notification about its
             * assignment.
             */
            $table->string('summary');
            $table->json('data')->nullable();

            /*
             * Which channels this went out on, beyond the row itself. A list
             * rather than a column, because #101's *"one notification type,
             * several channels, chosen per user"* is a set — issue #101's own
             * sketch has a scalar `channel`, which predates that sentence in
             * the same issue and would need three rows for one event, two of
             * them with a meaningless `read_at`.
             */
            $table->json('channels')->nullable();

            /*
             * When the outbound channels may go, or null for *now*. Set by
             * quiet hours and by nothing else.
             */
            $table->timestamp('deliver_after')->nullable();

            /*
             * Whether the outbound channels have gone. Distinct from
             * `read_at`, which is about the person: a notification can be
             * delivered and unread, or read in the panel before its email
             * went out.
             */
            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('read_at')->nullable();

            // The panel: this person's, newest first, unread ones first.
            $table->index(['person_id', 'read_at', 'created_at']);
            // The sweep that releases what quiet hours held.
            $table->index(['deliver_after', 'delivered_at']);
            // Grouping a burst: one type, one deal, one moment.
            $table->index(['person_id', 'type', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
