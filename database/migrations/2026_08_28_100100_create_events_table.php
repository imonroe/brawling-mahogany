<?php

declare(strict_types=1);

use App\Enums\EventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events (PRD §4.8 F8.1, §6.2 · S57, S58 · issue #105).
 *
 * A block of time somebody attends. Deliberately not the same table as
 * `key_dates`, which is a moment with legal consequences that nobody attends —
 * see `EventType`'s docblock for why collapsing the two loses the half that
 * matters.
 *
 * ## Every pointer out of here is nullable, and each for its own reason
 *
 * An **open house** belongs to a property and to no deal. A **closing
 * appointment** belongs to a deal and usually to its property. A **team
 * meeting** belongs to neither. And `stage_id` exists because F5.3's *create
 * calendar event* action hangs an event off the stage that produced it, which
 * is what puts an inspection on the deal's own timeline rather than only on
 * the grid.
 *
 * ## Instants, not days
 *
 * `starts_at` and `ends_at` are timestamps, and the `_at` suffix is doing what
 * `offers` says it does: this really is a moment. PRD §9 stores UTC and
 * displays the team's zone, and a 9am closing is 9am **where the closing is** —
 * a team member reading it from another timezone must not see it shift.
 *
 * `is_all_day` is the exception that keeps the rule honest. An open house
 * "on Saturday" has no start time somebody chose, and storing a fake midnight
 * would render as *"12:00am"* on every screen. The flag says which columns to
 * read as a day, and `starts_at` then holds that day's midnight in the team's
 * zone, so ordering by it still works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->productDefaults();

            $table->teamScopedForeign('deal_id', 'deals', nullable: true);
            $table->teamScopedForeign('property_id', 'properties', nullable: true);
            $table->teamScopedForeign('stage_id', 'stages', nullable: true);

            $table->string('type')->default(EventType::Other->value);
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_all_day')->default(false);

            /*
             * Free text, and it stays free text.
             *
             * A showing's location is *"lockbox on the side gate"* as often as
             * it is an address, and the address it usually is already lives on
             * `properties` where a link can reach it. A structured location
             * column would be a second, staler copy of the one that matters.
             */
            $table->string('location')->nullable();

            /*
             * Membership ids, never names or addresses.
             *
             * Everyone a team knows has a `team_memberships` row — that is
             * where Slice 1 put contact details, so a client, a vendor and a
             * colleague are all reachable the same way. Storing the id means
             * the name on a six-week-old event is the name the directory holds
             * today, and it means this column is not a third place a client's
             * email address is kept.
             *
             * It also matters for #108: an iCal feed leaves the building, and
             * a column with no addresses in it cannot leak one.
             */
            $table->config('attendees');

            /*
             * A repeat, described rather than expanded (S58's *recurring*).
             *
             * One row plus a rule, not one row per occurrence. A weekly open
             * house with no end date would be an unbounded INSERT, and editing
             * the series would mean finding every row it produced. The grid
             * expands the rule for the window it is drawing, and #108 hands
             * the rule to the client's calendar as an `RRULE` and lets it do
             * the same.
             *
             * `App\Support\Calendar\Recurrence` is the typed reading of it.
             */
            $table->config('recurrence');

            /*
             * S57 draws a window — one month, one week, a day. Both indexes
             * lead with the column that narrows first for their own query: the
             * grid has a team and a range, the deal tab has a deal.
             */
            $table->index(['team_id', 'starts_at']);
            $table->index(['deal_id', 'starts_at']);
            $table->index(['property_id', 'starts_at']);
        });

        DB::statement(sprintf(
            "ALTER TABLE events ADD CONSTRAINT events_type_check CHECK (type IN ('%s'))",
            implode("','", array_column(EventType::cases(), 'value')),
        ));

        /*
         * An event cannot end before it starts.
         *
         * The cheapest possible guard against the one mistake a date picker
         * makes, and the one that turns a month grid into nonsense — a
         * negative-length block has no square to draw in.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE events
                ADD CONSTRAINT events_ends_after_start_check
                CHECK (ends_at IS NULL OR ends_at >= starts_at)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
