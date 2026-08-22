<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access roles and the permission catalogue (PRD §4.2 F2.2, F2.3, §6.2, §9).
 *
 * PRD §7.2 calls the shape of this *"the single biggest simplification
 * available"*: Client, Buyer, Seller and Service Provider are **not** access
 * roles, they are relationships to one deal, and they move to
 * `deal_participants` in Slice 2. What is left is five genuine access tiers.
 *
 * Roles attach to the **membership**, not the person (`membership_role`), so
 * revoking somebody from one team cannot touch what they can do in another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Null for the five system roles, which every team shares.
            // PRD F2.3 lets a team owner compose their own alongside them.
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        // A system role's key is unique globally; a team role's is unique
        // within its team. Postgres treats NULLs as distinct in a unique
        // index, so the system half needs its own.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX roles_team_key_unique ON roles (team_id, key) WHERE deleted_at IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX roles_system_key_unique ON roles (key) WHERE team_id IS NULL AND deleted_at IS NULL
        SQL);

        // Flat, seeded in code (PRD §6.2). There is no per-team permission
        // catalogue: teams compose roles, they do not invent capabilities.
        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('group');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('membership_role', function (Blueprint $table): void {
            $table->foreignUlid('team_membership_id')->constrained('team_memberships')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['team_membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
