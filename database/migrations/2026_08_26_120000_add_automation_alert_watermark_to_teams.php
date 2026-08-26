<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far S91's alert has told each team (issue #97).
 *
 * ## Why this is a column and not a cache key
 *
 * It was a cache key for one round, and adversarial review named the two ways
 * that fails. The cache is Redis: it is **evictable** under memory pressure
 * and it is empty after a restart, so *"never twice about the same failure"*
 * — a promise `resources/help/automation.md` makes to a team in those words —
 * held only until an eviction. And a mark that has never been written falls
 * back to a cold-start floor, so a team whose failures could not be reported
 * (nobody holds `message.approve`, nobody has an address) is silenced about
 * them the moment those failures age past it.
 *
 * `CLAUDE.md` already carries the general form: *a cache is only true at the
 * moment something refreshed it.* A high-water mark is a durability
 * guarantee, and a cache is not a durable store — a promise that survives a
 * `redis-cli FLUSHALL` has to live where the rows live.
 *
 * ## Null means "never swept", and that is not the same as "the beginning"
 *
 * A team that has never been swept — a fresh install, a team created between
 * two runs — is held to a floor of the last day rather than to all of history,
 * so shipping this does not email somebody about a month of failures they have
 * already worked through. `AlertOnFailures::COLD_START_HOURS` is that floor,
 * and it applies exactly once per team because a team's **first sweep anchors
 * this column** whether or not it had anything to say. Leaving that out — so
 * that only a sweep with something to report wrote it — meant the floor was
 * re-derived from `now()` on every run and therefore slid forward, silently
 * losing any failure older than it for a team that had never had one. The
 * anchor is the whole reason a floor relative to `now()` is safe to use at
 * all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            /*
             * The **exclusive upper bound** of the last window reported, not
             * the timestamp of the last row in it. `action_instances.executed_at`
             * is `timestamp(0)`, so several failures share a second routinely
             * — and a mark set to a row's own timestamp, read back with a
             * strict `>`, silences every sibling that landed in that same
             * second after the sweep's `SELECT`. Half-open windows over a
             * boundary the sweep chooses are what make every instant belong to
             * exactly one window.
             */
            $table->timestamp('automation_alerted_through')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('automation_alerted_through');
        });
    }
};
