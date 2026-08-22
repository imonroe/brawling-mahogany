<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The join that makes multi-team work (PRD §4.1 F1.4, §6.2, §7.4).
 *
 * PRD §7.4 named the failure this corrects: the rough data model made Team to
 * User many-users-to-one-team, which *"breaks the moment a stager works for
 * two teams."*
 *
 * Everything a team knows privately about a person lives here rather than on
 * the person: the lifecycle status, the notes, the vendor assessment. A human
 * known to two teams is one `people` row and two memberships, and what Team A
 * wrote about them is not Team B's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_memberships', function (Blueprint $table): void {
            $table->productDefaults();
            $table->foreignUlid('person_id')->constrained('people')->cascadeOnDelete();

            // IA §8 person lifecycle. PRD §7.3: Past Client is a first-class
            // state that drives Keep in Touch, not an archive.
            $table->string('status')->default(PersonLifecycleState::Lead->value);

            /*
             * Vendor is a flag, not a lifecycle value — IA §13.3 asked the
             * question and this settles it. A stager can be a past client and
             * a vendor at the same time, which a single status column cannot
             * express, and the People index segments on it (IA §5.2).
             */
            $table->boolean('is_vendor')->default(false);
            $table->config('vendor_specialties');
            $table->money('vendor_typical_cost', nullable: true);
            $table->string('vendor_service_area')->nullable();
            $table->unsignedSmallInteger('vendor_rating')->nullable();
            $table->text('vendor_notes')->nullable();

            // PRD §6.2: team-private notes live here, not on the person.
            $table->text('notes')->nullable();

            // PRD §4.2 F2.7: a role may contribute extra profile fields.
            $table->config('role_fields');

            $table->timestamp('joined_at')->nullable();

            /*
             * PRD F1.3: "revoke without destroying historical attribution."
             * A revoked membership keeps every activity event, task
             * completion, and audit entry the person authored. Set this;
             * never delete the row.
             */
            $table->timestamp('revoked_at')->nullable();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'is_vendor']);
        });

        // One membership per person per team. Partial, so a soft-deleted
        // membership does not lock the person out of rejoining.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX team_memberships_team_person_unique
            ON team_memberships (team_id, person_id)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(sprintf(
            "ALTER TABLE team_memberships ADD CONSTRAINT team_memberships_status_check CHECK (status IN ('%s'))",
            implode("','", PersonLifecycleState::values()),
        ));

        DB::statement(
            'ALTER TABLE team_memberships ADD CONSTRAINT team_memberships_vendor_rating_check '.
            'CHECK (vendor_rating IS NULL OR vendor_rating BETWEEN 1 AND 5)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
    }
};
