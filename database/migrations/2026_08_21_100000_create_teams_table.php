<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant boundary (PRD §4.1 F1.1, §6.2 · IA §2).
 *
 * `teams` is the one table that is not itself team-scoped: it is the thing
 * every other table is scoped *to*. Everything else in the product carries
 * `team_id` and inherits the enforcement layers in ADR 0002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();

            // Branding. Design System §2.7 layers the team's accent over the
            // token system on client-facing surfaces only, so the internal app
            // does not change colour per team.
            $table->string('logo_path')->nullable();
            $table->string('brand_accent_color', 7)->nullable();

            // Slice 3 sends as this identity, with reply-to pointing at the
            // agent rather than at the platform.
            $table->string('sending_identity_name')->nullable();
            $table->string('sending_identity_email')->nullable();
            $table->text('signature_block')->nullable();

            // PRD §9: UTC in storage, team timezone in display. Everything
            // that renders a time reads this.
            $table->string('timezone')->default('UTC');

            // The safety rails live here (PRD §4.5 F5.9): the hard no-sends
            // switch and the per-team send rate limit, from Slice 3.
            $table->config('settings');

            // Super-admin suspension (S83) and the cancellable tenant purge
            // window (PRD §9 Deletion, issue #57).
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('purge_after')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
