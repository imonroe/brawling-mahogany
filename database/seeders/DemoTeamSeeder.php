<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Teams\ProvisionTeam;
use App\Enums\ActivitySource;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\PropertyInterest;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\ExternalLink;
use App\Models\GateTemplate;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Activity\RecordActivity;
use App\Support\Deals\DealRoster;
use App\Support\Deals\DealTasks;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\AdvanceWorkflow;
use App\Support\Workflow\InstantiateWorkflow;
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

            // No lifecycle: a colleague has no place on IA §8's contact
            // vocabulary, and the column says so rather than holding `active`
            // and reading as *Client* (#162).
            $this->attach($team, $member, SystemRole::TeamMember, null, $memberDetails);

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

            $this->properties(
                $team->memberships()->where('person_id', $client->getKey())->sole(),
            );

            $this->workflow($team);
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
    private function properties(TeamMembership $client): void
    {
        // `whereParcel()` rather than a raw `where`: a lookup by value has to
        // fold and trim the way the index does, or a second run inserts a
        // duplicate it cannot see.
        $listed = Property::query()->whereParcel('0512-14-002-0031')->first() ?? Property::query()->create([
            'parcel_number' => '0512-14-002-0031',
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
        ]);

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

        Property::query()->whereParcel('0512-14-002-0044')->first() ?? Property::query()->create([
            'parcel_number' => '0512-14-002-0044',
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
        ]);

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

        // No hand-written `generated_name` here: `PropertyDeals::link()` names
        // the deal through `NameDeal`, which is the product behaviour rather
        // than a seeder making the screenshot look right.
        app(PropertyDeals::class)->link($listed, $deal);

        /*
         * And a buyer's deal with two candidates, so S20's other half — the
         * one #62 is actually about — has demo data.
         *
         * No subject on it, deliberately: a buyer-side deal does not have one
         * until an offer is accepted, and that empty state is the case the
         * definition of done names. The deal is named after the client until
         * then, which is IA §10's fallback doing its job rather than a gap.
         */
        $buyerDeal = Deal::query()->create([
            'deal_type_id' => DealType::query()->whereNull('team_id')
                ->where('name', 'Buyer Representation')->sole()->getKey(),
            'opened_at' => now()->subWeek(),
        ]);

        $mapleton = Property::query()->whereParcel('0512-14-002-0044')->first();

        /*
         * The client, so IA §10's fallback has something to fall back *to*.
         * A buyer's deal with no subject is named after them — "Nakamura
         * Purchase" — and a seed that left the participant off would have
         * shown "Untitled deal", which is the one thing that rule exists to
         * prevent.
         */
        app(DealRoster::class)->add(
            deal: $buyerDeal,
            membership: $client,
            role: ParticipantRole::Buyer,
            isPrimary: true,
        );

        $links = app(PropertyDeals::class);

        // `$listed` is unconditionally in scope; only the Mapleton fixture has
        // to be looked up, so only that one is guarded. Guarding both meant
        // moving one fixture would silently leave the demo buyer deal empty.
        if ($mapleton instanceof Property) {
            $links->describe(
                $links->link($mapleton, $buyerDeal),
                ['interest_status' => PropertyInterest::Shortlisted],
            );
        }

        $links->describe(
            $links->link($listed, $buyerDeal),
            ['interest_status' => PropertyInterest::Passed],
        );
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
     * A listing template, and one deal running on it (S39–S43, S15–S17).
     *
     * ## Why the demo seed needs this at all
     *
     * Slices 0 to 2 built the workflow engine and every screen over it, and
     * `migrate:fresh --seed` produced a team with **no workflow template and
     * no running workflow** — so the timeline, the stage rail, the tasks tab,
     * the advance modal, My Work and the dashboard's two headline counts were
     * all empty on a fresh install. `CLAUDE.md` advertises this command as
     * *"a working demo team"*, and the slice that makes the product exist was
     * the part it could not demonstrate.
     *
     * ## This is not #87, and must not be mistaken for it
     *
     * #87 seeds **Emily's real listing checklist** as a shipped pack, and is
     * blocked on #11 because a pack whose stages somebody invented teaches a
     * process nobody follows and gets copied before anyone notices. What is
     * here is the demo team's **own** template — `team_id` set, no
     * `template_pack_id` — four stages sketched so the screens have something
     * to draw. Nobody inherits it, and no install but a developer's has it.
     *
     * ## It is advanced by the product, never by hand
     *
     * The deal is walked forward through `AdvanceWorkflow` and
     * `DealTasks`, which is why the seeded history is real: the timeline
     * entries, the `stages.state` values and the task completions are the ones
     * those services write. A seeder that set `state` directly would produce a
     * screenshot rather than a deal, and `SingleMutationPathTest` would refuse
     * it anyway.
     */
    private function workflow(Team $team): void
    {
        if (WorkflowTemplate::query()->where('team_id', $team->getKey())->exists()) {
            return;
        }

        $template = WorkflowTemplate::query()->create([
            'team_id' => $team->getKey(),
            'name' => 'Listing to Close',
            'description' => 'A sketch, so the screens have something to draw. Emily’s real list is issue #11.',
            'version' => 1,
            'is_active' => true,
        ]);

        /*
         * Four stages, and the shape matters more than the content: a
         * milestone with a client-facing sentence (IA §3 and §9), a manual
         * gate somebody can tick, and a tasks gate that clears by doing the
         * work. Between them they exercise both routine ways past a gate,
         * which is the pair S17 and the confirmation route exist to provide.
         */
        $stages = [
            ['Pre-listing', 'Get the house ready and the paperwork signed.', false, null, [
                ['manual_confirmation', 'Seller signed the listing agreement'],
            ], ['Walk the property', 'Book the photographer']],
            ['On Market', 'Live on the MLS, showings running.', true, 'Your home is on the market.', [
                ['required_tasks_complete', 'Listing tasks are done'],
            ], ['Publish the listing', 'Hold the first open house']],
            ['Under Contract', 'An offer is accepted and the clock is running.', true, 'You are under contract.', [
                ['manual_confirmation', 'Inspection is complete'],
            ], ['Order the inspection', 'Confirm the appraisal date']],
            ['Closing', 'Final walkthrough, signing, keys.', true, 'Your sale has closed.', [], ['Schedule the walkthrough']],
        ];

        foreach ($stages as $index => [$name, $description, $isMilestone, $clientLabel, $gates, $tasks]) {
            $stage = StageTemplate::query()->create([
                'workflow_template_id' => $template->getKey(),
                'name' => $name,
                'description' => $description,
                'sort_order' => $index,
                'expected_duration_days' => 14,
                'is_milestone' => $isMilestone,
                'client_facing_label' => $clientLabel,
            ]);

            foreach ($gates as $order => [$type, $label]) {
                GateTemplate::query()->create([
                    'stage_template_id' => $stage->getKey(),
                    'gate_type' => $type,
                    'label' => $label,
                    'is_blocking' => true,
                    'sort_order' => $order,
                ]);
            }

            foreach ($tasks as $order => $title) {
                TaskTemplate::query()->create([
                    'stage_template_id' => $stage->getKey(),
                    'title' => $title,
                    'is_required' => true,
                    'due_offset_days' => $order * 3,
                    'sort_order' => $order,
                ]);
            }
        }

        $deal = Deal::query()->whereNotNull('generated_name')->orderBy('created_at')->first();

        if (! $deal instanceof Deal) {
            return;
        }

        $agent = $team->memberships()
            // `holdingSystemRole()`, not a raw `roles.key` — a team may compose
            // a role and a grep is how the next person decides whether the
            // counterfeit-owner fix is complete.
            ->holdingSystemRole(SystemRole::TeamOwner->value)
            ->sole();

        $workflow = app(InstantiateWorkflow::class)->handle($deal, $template, now()->subWeeks(3));

        /*
         * Walked one stage forward, so the demo opens on a deal **mid-flight**
         * rather than on stage one of four. That is the state every screen is
         * designed around — a completed stage above, an active one with an
         * unmet requirement, and future stages below — and it is the only one
         * that shows the stage rail doing anything.
         *
         * The advance is earned rather than forced: the manual gate is
         * confirmed and both required tasks completed, through the same two
         * services a person would use.
         */
        $advance = app(AdvanceWorkflow::class);
        $person = $agent->person;
        $first = $workflow->stages()->orderBy('sort_order')->firstOrFail();

        foreach ($first->gates as $gate) {
            $advance->confirm($workflow->fresh(), $gate, $person);
        }

        $tasks = app(DealTasks::class);

        foreach (Task::query()->where('deal_id', $deal->getKey())->whereNull('completed_at')->get() as $task) {
            $stage = $task->stage;

            if ($stage instanceof Stage && $stage->sort_order === 0) {
                $tasks->complete($deal, $task, $person);
            }
        }

        $advance->handle($workflow->fresh(), $person);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string}  $details
     */
    private function attach(Team $team, Person $person, SystemRole $role, ?PersonLifecycleState $status, array $details): TeamMembership
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
