<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `activity_events.summary` becomes `text`.
 *
 * For every event type but one, the summary is a sentence the product wrote
 * about something that happened elsewhere, and 255 characters is generous.
 * **A note has no elsewhere.** F4.11's note *is* its summary — deliberately,
 * because putting the body in `payload` and a generic "Note added" here would
 * give the timeline a row nobody can read without expanding it, and the status
 * page a row with nothing in it at all.
 *
 * So `StoreNoteRequest` allowed 5000 characters into a `varchar(255)`, and a
 * note of more than about four lines was a 500 that lost what somebody had
 * just typed. The validation rule and the column were each defensible and
 * disagreed.
 *
 * Widening rather than capping, because the cap would have to be ~250
 * characters to be safe and a paragraph about a client call is an ordinary
 * note. In Postgres this is a metadata-only change on an unindexed column —
 * `varchar(255)` and `text` share a representation — so it does not rewrite
 * the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Raw rather than `$table->text('summary')->change()`, because
         * `change()` needs the column's full definition restated and silently
         * drops anything omitted — a nullable or a default lost that way is
         * not visible in the diff.
         *
         * (An earlier version of this comment justified it by append-only
         * triggers on this table. There are none: `audit_log` carries those,
         * `activity_events` does not. The migration was right and the reason
         * recorded for it was wrong, which in this repository is the half that
         * lasts.)
         */
        DB::statement('ALTER TABLE activity_events ALTER COLUMN summary TYPE text');
    }

    public function down(): void
    {
        /*
         * Truncated on the way back, because a row longer than the old column
         * cannot fit it — and failing the rollback would leave the schema half
         * migrated, which is worse than a shortened summary in a direction
         * nobody runs in production.
         */
        DB::statement('ALTER TABLE activity_events ALTER COLUMN summary TYPE varchar(255) USING left(summary, 255)');
    }
};
