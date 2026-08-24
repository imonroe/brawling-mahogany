<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A colleague has no place on the client lifecycle, and the column can say so.
 *
 * `status` was `NOT NULL DEFAULT 'lead'`, so every membership held a value from
 * IA §8's **contact** vocabulary whether or not anybody had classified the
 * person. `AcceptInvitation` wrote `active` for want of something to write, and
 * `active` reads as *Client* — which is issue #162: a team's own assistant
 * badged as their client.
 *
 * The first fix asked *"does this person carry team access?"* and hid the badge
 * when they did. That works right up until access is revoked, at which point
 * the row falls back to a lifecycle value nobody ever chose and the same green
 * *Client* returns. The question is unanswerable from the data: there is no way
 * to tell `active`-because-somebody-said-so from `active`-because-a-column-had-
 * to-hold-something.
 *
 * Null is that distinction. **No lifecycle** is now a state a membership can be
 * in, it is the state a colleague is in, and a screen that has nothing to say
 * about somebody says nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->string('status')->nullable()->default(null)->change();
        });

        /*
         * Every colleague's value was written by `AcceptInvitation` or
         * `ProvisionTeam`, never by a human — the form that offered the choice
         * is the bug in #162 — so clearing it loses no classification anybody
         * made. It is scoped by the same question `carriesAccess()` asks, in
         * SQL: at least one permission on the team surface.
         */
        DB::table('team_memberships')
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('membership_role')
                ->join('role_permission', 'role_permission.role_id', '=', 'membership_role.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
                ->whereColumn('membership_role.team_membership_id', 'team_memberships.id')
                ->whereIn('permissions.key', Permissions::teamSurfaceKeys()))
            ->update(['status' => null]);
    }

    public function down(): void
    {
        // The column cannot go back to NOT NULL with nulls in it, and the
        // value it held for a colleague was never meaningful — so restore the
        // one this migration cleared.
        DB::table('team_memberships')
            ->whereNull('status')
            ->update(['status' => PersonLifecycleState::Active->value]);

        Schema::table('team_memberships', function (Blueprint $table): void {
            $table->string('status')->nullable(false)->default(PersonLifecycleState::Lead->value)->change();
        });
    }
};
