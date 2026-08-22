<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Teams\ProvisionTeam;
use App\Enums\ActivitySource;
use App\Enums\PersonLifecycleState;
use App\Enums\SystemRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A working team for local development (issue #39).
 *
 * Everybody here is fictional. The shape is real though: an owner and a member
 * who can both sign in, a super administrator who is not in the team at all,
 * and a directory of people who mostly cannot sign in — because PRD F2.1 says
 * most people in this product never do, and a seed where everyone has
 * credentials teaches the wrong lesson about the data model.
 */
class DemoTeamSeeder extends Seeder
{
    public function run(): void
    {
        $teams = app(TeamContext::class);

        $superAdmin = $this->person('ian@example.test', 'Ian', 'Monroe', superAdmin: true);

        $owner = $this->person('emily@example.test', 'Emily', 'Bosart');
        $member = $this->person('heather@example.test', 'Heather', 'Quinn');

        $team = Team::query()->where('slug', 'demo-team')->first()
            ?? app(ProvisionTeam::class)->handle(
                ['name' => 'Demo Team', 'slug' => 'demo-team', 'timezone' => 'America/Denver'],
                $owner,
            );

        $teams->runFor($team, function () use ($team, $member): void {
            $this->attach($team, $member, SystemRole::TeamMember, PersonLifecycleState::Active);

            $client = $this->person('claire@example.test', 'Claire', 'Nakamura', canSignIn: false);
            $lead = $this->person('lee@example.test', 'Lee', 'Okonkwo', canSignIn: false);
            $stager = $this->person('sam@example.test', 'Sam', 'Ferreira', canSignIn: false);

            $this->attach($team, $client, SystemRole::Contact, PersonLifecycleState::Active);
            $this->attach($team, $lead, SystemRole::Contact, PersonLifecycleState::Lead);

            $stagerMembership = $this->attach($team, $stager, SystemRole::Contact, PersonLifecycleState::PastClient);
            $stagerMembership->forceFill([
                'is_vendor' => true,
                'vendor_specialties' => ['staging', 'photography'],
                'vendor_service_area' => 'Denver metro',
                'vendor_rating' => 5,
                'vendor_typical_cost' => 120_000,
            ])->save();

            app(RecordActivity::class)->record(
                subject: $client,
                eventType: 'contact.logged',
                summary: 'Phone call',
                source: ActivitySource::Manual,
                payload: ['contact_type' => 'phone_call', 'note' => 'Walked through the listing timeline.'],
            );
        });

        $this->command->info("Demo team seeded. Sign in as emily@example.test / password (super admin: {$superAdmin->email}).");
    }

    private function person(string $email, string $first, string $last, bool $superAdmin = false, bool $canSignIn = true): Person
    {
        return Person::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => '+1 303 555 0100',
                'is_super_admin' => $superAdmin,
                'email_verified_at' => $canSignIn ? now() : null,
                // PRD F2.1: credentials are optional, and for most of the
                // directory they are simply absent.
                'password' => $canSignIn ? Hash::make('password') : null,
            ],
        );
    }

    private function attach(Team $team, Person $person, SystemRole $role, PersonLifecycleState $status): TeamMembership
    {
        $membership = TeamMembership::query()->firstOrCreate(
            ['team_id' => $team->getKey(), 'person_id' => $person->getKey()],
            ['status' => $status, 'joined_at' => now()],
        );

        $membership->roles()->syncWithoutDetaching([
            Role::query()->where('key', $role->value)->whereNull('team_id')->sole()->getKey(),
        ]);

        return $membership;
    }
}
