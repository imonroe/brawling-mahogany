<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\PersonLifecycleState;
use App\Enums\SystemRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a team (PRD §5.1 step 1 · Screen Inventory S83).
 *
 * *"Ian provisions a team and invites the owner."* This is where a customer's
 * life in the product begins, and Slice 1's exit criterion starts here.
 *
 * Provisioning runs from the super admin console, which is outside every
 * team's context by definition — so the whole thing happens inside an explicit
 * `runFor()` rather than hoping the ambient team is right.
 */
final class ProvisionTeam
{
    public function __construct(
        private readonly TeamContext $teams,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?Person $owner = null): Team
    {
        return DB::transaction(function () use ($attributes, $owner): Team {
            $team = Team::query()->create([
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? $this->slugFor($attributes['name']),
                'timezone' => $attributes['timezone'] ?? config('app.timezone'),
                'settings' => [],
            ]);

            $this->audit->record(
                action: 'team.provisioned',
                auditable: $team,
                teamId: $team->getKey(),
                after: ['name' => $team->name, 'slug' => $team->slug],
            );

            if ($owner instanceof Person) {
                $this->teams->runFor($team, fn () => $this->attachOwner($team, $owner));
            }

            return $team;
        });
    }

    /**
     * The first Team Owner.
     *
     * A team without one cannot be administered at all, which is the other
     * half of the last-owner rule enforced in RevokeMembership.
     */
    public function attachOwner(Team $team, Person $owner): TeamMembership
    {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $owner->getKey(),
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->where('key', SystemRole::TeamOwner->value)->whereNull('team_id')->sole()->getKey(),
        );

        $this->audit->record(
            action: 'team.owner_attached',
            auditable: $membership,
            teamId: $team->getKey(),
            after: ['person_id' => $owner->getKey(), 'role' => SystemRole::TeamOwner->value],
        );

        return $membership;
    }

    /**
     * A slug that is unique without asking the caller to check.
     */
    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = $base;
        $suffix = 2;

        while (Team::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
