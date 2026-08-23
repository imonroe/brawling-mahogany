<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Teams\ProvisionTeam;
use App\Enums\ActivitySource;
use App\Enums\PersonLifecycleState;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\ExternalLink;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Properties\PropertyDeals;
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

        [$superAdmin, $superAdminDetails] = $this->person('ian@example.test', 'Ian', 'Monroe', superAdmin: true);

        [$owner, $ownerDetails] = $this->person('emily@example.test', 'Emily', 'Bosart');
        [$member, $memberDetails] = $this->person('heather@example.test', 'Heather', 'Quinn');

        $team = Team::query()->where('slug', 'demo-team')->first()
            ?? app(ProvisionTeam::class)->handle(
                ['name' => 'Demo Team', 'slug' => 'demo-team', 'timezone' => 'America/Denver'],
                $owner,
            );

        $teams->runFor($team, function () use ($team, $owner, $ownerDetails, $member, $memberDetails): void {
            // The owner's membership exists already, from provisioning; it
            // still needs the name this team knows them by (#140).
            $team->memberships()->where('person_id', $owner->getKey())->first()
                ?->forceFill($ownerDetails)->save();

            $this->attach($team, $member, SystemRole::TeamMember, PersonLifecycleState::Active, $memberDetails);

            [$client, $clientDetails] = $this->person('claire@example.test', 'Claire', 'Nakamura', canSignIn: false);
            [$lead, $leadDetails] = $this->person('lee@example.test', 'Lee', 'Okonkwo', canSignIn: false);
            [$stager, $stagerDetails] = $this->person('sam@example.test', 'Sam', 'Ferreira', canSignIn: false);

            $this->attach($team, $client, SystemRole::Contact, PersonLifecycleState::Active, $clientDetails);
            $this->attach($team, $lead, SystemRole::Contact, PersonLifecycleState::Lead, $leadDetails);

            $stagerMembership = $this->attach(
                $team, $stager, SystemRole::Contact, PersonLifecycleState::PastClient, $stagerDetails,
            );

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

            $this->properties();
        });

        $this->command->info("Demo team seeded. Sign in as emily@example.test / password (super admin: {$superAdmin->email}).");

        unset($superAdminDetails);
    }

    /**
     * Two houses, so S35 and S36 have something to render (#61).
     *
     * One on the market with links out, one pre-listing with none — the two
     * shapes the detail screen has to handle, and the empty links panel is the
     * one somebody would otherwise only see by deleting a row.
     *
     * The links go to `.test` addresses on purpose. A seed that pointed at a
     * real listing site would put a request to somebody else's servers in
     * every developer's `migrate:fresh --seed`, and PRD §10 is emphatic that
     * this product links out and never fetches.
     */
    private function properties(): void
    {
        $listed = Property::query()->firstOrCreate(
            ['parcel_number' => '0512-14-002-0031'],
            [
                'street' => '1420 Pearl St',
                'city' => 'Boulder',
                'state_code' => 'CO',
                'postal_code' => '80302',
                'type' => PropertyType::SingleFamily,
                'status' => PropertyStatus::ForSale,
                'beds' => 3,
                'baths' => '2.5',
                'sqft' => 1840,
                'year_built' => 1962,
                'notes' => 'Seller wants a Thursday listing date.',
            ],
        );

        foreach ([
            ['Listing', 'https://listings.example.test/1420-pearl-st'],
            ['County assessor', 'https://assessor.example.test/parcel/0512-14-002-0031'],
        ] as $position => [$label, $url]) {
            $link = new ExternalLink;
            $link->forceFill([
                'linkable_type' => $listed->getMorphClass(),
                'linkable_id' => $listed->getKey(),
            ]);
            $link->fill(['label' => $label, 'url' => $url, 'sort_order' => $position]);

            if (! $listed->externalLinks()->where('url', $url)->exists()) {
                $link->save();
            }
        }

        Property::query()->firstOrCreate(
            ['parcel_number' => '0512-14-002-0044'],
            [
                'street' => '88 Mapleton Ave',
                'city' => 'Boulder',
                'state_code' => 'CO',
                'postal_code' => '80304',
                'type' => PropertyType::Condo,
                'status' => PropertyStatus::PreListing,
                'beds' => 2,
                'baths' => '1.0',
                'sqft' => 960,
                'year_built' => 1998,
            ],
        );

        /*
         * And one deal, so S36's "linked deals" is not an empty state.
         *
         * That panel is what the definition of done is written about, and a
         * seed that left it empty would have shown the one case nobody needed
         * help imagining. The deal type is a seeded system row — every install
         * has the three (PRD §2.2).
         */
        if (Deal::query()->exists()) {
            return;
        }

        $deal = Deal::query()->create([
            'deal_type_id' => DealType::query()->whereNull('team_id')
                ->where('name', 'Seller Representation')->sole()->getKey(),
            'name' => null,
            'opened_at' => now()->subWeeks(3),
        ]);

        $deal->forceFill(['generated_name' => $listed->displayName()])->save();

        app(PropertyDeals::class)->link($listed, $deal);
    }

    /**
     * A login, plus the name and number to hang on a membership.
     *
     * Since #140 the two are separate records, so this returns the person and
     * `attach()` carries the details onto the team's row. A person who cannot
     * sign in gets no `email` here at all — the address a team holds for them
     * is the team's, not an account's.
     *
     * @return array{0: Person, 1: array{first_name: string, last_name: string, email: string, phone: string}}
     */
    private function person(string $email, string $first, string $last, bool $superAdmin = false, bool $canSignIn = true): array
    {
        $person = $canSignIn
            ? Person::query()->firstOrCreate(
                ['email' => $email],
                [
                    'is_super_admin' => $superAdmin,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ],
            )
            // PRD F2.1: credentials are optional, and for most of the
            // directory they are simply absent.
            : Person::query()->create(['is_super_admin' => false]);

        return [$person, [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => '+1 303 555 0100',
        ]];
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string}  $details
     */
    private function attach(Team $team, Person $person, SystemRole $role, PersonLifecycleState $status, array $details): TeamMembership
    {
        $membership = TeamMembership::query()->firstOrCreate(
            ['team_id' => $team->getKey(), 'person_id' => $person->getKey()],
            ['status' => $status, 'joined_at' => now(), ...$details],
        );

        $membership->roles()->syncWithoutDetaching([
            Role::query()->where('key', $role->value)->whereNull('team_id')->sole()->getKey(),
        ]);

        return $membership;
    }
}
