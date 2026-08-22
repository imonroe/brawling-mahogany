<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only security record (PRD §6.2, §9 Audit · `CLAUDE.md`).
 *
 * Deliberately **not** `productDefaults()`. That macro gives a table
 * `updated_at` and `deleted_at`, and an audit row must have neither: a record
 * that can be edited is not evidence, and a record that can be soft-deleted
 * disappears exactly when somebody wants it to.
 *
 * `team_id` is nullable for the one case that has no team — a failed sign-in
 * against an address that belongs to nobody — and for the platform-level
 * entries the super admin console writes about a team it is looking *at*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->nullable()->index();
            $table->foreignUlid('actor_person_id')->nullable()->index();

            $table->string('action')->index();
            $table->string('auditable_type')->nullable();
            $table->ulid('auditable_id')->nullable();

            // PRD §9: "No PII in logs, ever." App\Support\Audit\AuditRedactor
            // strips known-sensitive attributes to a names-changed list before
            // either of these is written.
            $table->config('before');
            $table->config('after');

            // PRD F4.9: overriding a gate requires a typed reason, recorded
            // immutably. That is this column, and it is why it exists.
            $table->text('reason')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at');

            $table->index(['team_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        /*
         * Append-only, enforced rather than intended.
         *
         * The model refuses updates and deletes (App\Models\AuditEntry), but a
         * model is a convention and a rule is a rule. These triggers hold even
         * for a raw query, a tinker session, or a future developer who has not
         * read the ADR.
         *
         * PRD §9's stronger form — revoking UPDATE and DELETE from the
         * application's database role — needs a second role the deployment
         * does not have yet; see docs/Deployment.md. This is the floor, not
         * the ceiling.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_is_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_log_no_update
                BEFORE UPDATE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only();

            CREATE TRIGGER audit_log_no_delete
                BEFORE DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');

        DB::unprepared('DROP FUNCTION IF EXISTS audit_log_is_append_only()');
    }
};
