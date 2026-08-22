<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links out to somewhere else (PRD §4.3 F3.4, §7.13, §10 · S36, S37 · #61).
 *
 * ## Why this is a table and not columns
 *
 * PRD §7.13, verbatim: *"Per-site link columns will not scale."* The rough
 * data model had `zillow_url`, and the twelfth site an agent cares about would
 * have been the twelfth migration. A label plus a URL is the whole shape, so
 * the twelfth site is a row.
 *
 * ## And why it is only ever a link
 *
 * PRD §10 is the sharpest constraint in the product: MLS listing data is
 * licensed, and *"v1 stores links only, never ingested listing content."*
 * There is deliberately no column here for a title, a price, a photo, or a
 * description — nothing that could hold a copy of what is on the other end.
 * Emily's complaint that a competitor makes you upload an MLS sheet is, in the
 * PRD's words, *"a symptom of the same constraint, not a solvable product
 * gap"*. A `fetched_title` column added later for convenience would be the
 * first step across a licensing line, and the absence is the guard.
 *
 * ## The hole the composite keys cannot cover
 *
 * ADR 0002 layer 2 makes a cross-tenant pointer unrepresentable by carrying
 * `team_id` into every foreign key. A **polymorphic** pointer cannot do that:
 * `(team_id, linkable_id)` has no single table to reference, so Postgres has
 * nothing to check it against. This is the same shape as `activity_events`
 * and `audit_log`, and the answer is the same one ADR 0002 already gives for
 * `deals.deal_type_id` — *"where Postgres cannot express the constraint, the
 * relationship carries a test instead"* — plus a model guard, because a test
 * alone was walked past in review once already.
 *
 * `ExternalLink::booted()` refuses a linkable in another team, and refuses a
 * `linkable_type` that is not on its allowlist. `team_id` is still here and
 * still NOT NULL: the global scope, the middleware, the policies and the
 * retention purge all key on it, and a table without one is outside every one
 * of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_links', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * Properties today, deals next. The column holds a class name
             * rather than a short alias because `activity_events` and
             * `audit_log` already do, and one morph convention per schema is
             * worth more than the shorter string.
             */
            $table->string('linkable_type');
            $table->ulid('linkable_id');

            /*
             * What to call it. Free text on purpose: "Zillow", "County
             * assessor", "The listing agent's virtual tour" are all things
             * somebody will type, and a lookup here would be the per-site
             * column problem again wearing a different hat.
             */
            $table->string('label');

            // `text`, not `string`. A signed MLS URL is routinely past 255.
            $table->text('url');

            // S37 lets somebody order them; the first one is the one they
            // meant.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['team_id', 'linkable_type', 'linkable_id']);
        });

        /*
         * The same link twice on the same record is a duplicate with no
         * meaning, and two rows that differ only in case are the same link.
         *
         * Partial on `deleted_at`, so removing a link frees it again.
         *
         * Deliberately **not** unique on `(linkable, label)`: two links can
         * legitimately share a label — a property with the county assessor's
         * page for each of two parcels — and refusing what somebody might mean
         * is worse than allowing a repeat.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX external_links_unique_url
                ON external_links (linkable_type, linkable_id, lower(url))
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('external_links');
    }
};
