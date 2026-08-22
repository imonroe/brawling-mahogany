<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations (PRD §4.1 F1.3 · Screen Inventory S04, S74, S90).
 *
 * F1.3: *"Invite by email, assign role on invite, revoke without destroying
 * historical attribution."*
 *
 * The token is stored hashed, never in plain text. An invitation link is a
 * credential for the duration of its life, and a leaked database dump should
 * not be a set of working keys to every pending invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->productDefaults();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('invited_by_person_id')->nullable()->constrained('people')->nullOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['team_id', 'email']);
        });

        // One live invitation per address per team, so a double click does not
        // produce two emails with two different working tokens.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX team_invitations_pending_unique
            ON team_invitations (team_id, lower(email))
            WHERE deleted_at IS NULL AND accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
