<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Person;
use App\Models\Property;
use App\Models\StageTemplate;
use App\Models\TeamMembership;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * The three JSON endpoints S14 and S28 added (issue #74).
 *
 * `PeopleIndexBudgetTest` states the house standard and the reason:
 * *"a per-row query for the person behind each membership is how this screen
 * becomes unusable at exactly the point a customer starts relying on it."*
 *
 * Two of these fire on a 250ms debounce as somebody types, and the third
 * carries a preview — every template's stage names — which is the shape most
 * likely to grow a query per row. `ParticipantsBudgetTest` shipped with
 * exactly that defect on the endpoint that could least afford it, so these are
 * pinned before rather than after.
 *
 * **Equality, not a ceiling.** A budget of "under 40" would have passed on the
 * version `ParticipantsBudgetTest` replaced, at 33.
 */
function countWizardQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

/**
 * Memberships whose name carries the search term.
 *
 * Its own rather than `ParticipantsBudgetTest`'s `seedCandidates()`: Pest
 * shares top-level functions across the whole suite, so borrowing one makes
 * this file fail when that one is renamed, for a reason nothing here explains.
 *
 * @return array{0: App\Models\Team, 1: Person}
 */
function seedWizardClients(int $count, string $label): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $label): void {
        for ($i = 0; $i < $count; $i++) {
            TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => Person::factory()->create()->getKey(),
                'first_name' => "{$label}{$i}",
                'last_name' => 'Prospect',
                'status' => PersonLifecycleState::Active,
            ]);
        }
    });

    return [$team, $member];
}

/**
 * Properties whose street carries the search term, each with a deal link so
 * `withCount('dealLinks')` is answering something rather than returning zero
 * from an empty table.
 *
 * @return array{0: App\Models\Team, 1: Person}
 */
function seedWizardProperties(int $count, string $street): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $street): void {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        for ($i = 0; $i < $count; $i++) {
            $property = Property::factory()->create([
                'team_id' => $team->getKey(),
                'street' => "{$i} {$street} Way",
            ]);

            DealProperty::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
                'property_id' => $property->getKey(),
            ]);
        }
    });

    return [$team, $member];
}

/** @return array{0: App\Models\Team, 1: Person} */
function seedTemplates(int $count, string $label): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $label): void {
        $pack = TemplatePack::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            $template = WorkflowTemplate::factory()->create([
                'team_id' => $team->getKey(),
                'template_pack_id' => $pack->getKey(),
                'name' => "{$label} template {$i}",
            ]);

            // Three stages each, so a per-template preview query would show
            // up as growth rather than hiding in a constant.
            for ($stage = 0; $stage < 3; $stage++) {
                StageTemplate::factory()->create([
                    'workflow_template_id' => $template->getKey(),
                    'sort_order' => $stage,
                ]);
            }
        }
    });

    return [$team, $member];
}

it('does not grow the attach-workflow preview’s query count with the templates', function (): void {
    [$smallTeam, $smallMember] = seedTemplates(2, 'small');

    $this->actingAsPerson($smallMember, $smallTeam);

    $smallDeal = app(TeamContext::class)->runFor(
        $smallTeam,
        fn () => Deal::factory()->create(['team_id' => $smallTeam->getKey()]),
    );

    $small = countWizardQueries(
        // The row count is asserted as well as the status, because "equal
        // query counts" is also what an endpoint that returned nothing would
        // report — the vacuous-assertion shape this slice keeps producing.
        fn () => $this->getJson("/deals/{$smallDeal->getKey()}/workflows/available")
            ->assertOk()
            ->assertJsonCount(2, 'templates'),
    );

    [$largeTeam, $largeMember] = seedTemplates(20, 'large');

    $this->actingAsPerson($largeMember, $largeTeam);

    $largeDeal = app(TeamContext::class)->runFor(
        $largeTeam,
        fn () => Deal::factory()->create(['team_id' => $largeTeam->getKey()]),
    );

    $large = countWizardQueries(
        fn () => $this->getJson("/deals/{$largeDeal->getKey()}/workflows/available")
            ->assertOk()
            ->assertJsonCount(20, 'templates'),
    );

    expect($large)->toBe(
        $small,
        'The attach-workflow endpoint gained queries as the template list grew. '
        .'The stage names are eager-loaded in one query; a per-template `stages` '
        .'read is the usual cause.',
    );
});

it('does not grow the wizard’s client typeahead with the directory', function (): void {
    [$smallTeam, $smallMember] = seedWizardClients(2, 'Wizsmall');

    $this->actingAsPerson($smallMember, $smallTeam);

    $small = countWizardQueries(
        fn () => $this->getJson('/deals/create/clients?q=Prospect')
            ->assertOk()
            ->assertJsonCount(2, 'people'),
    );

    [$largeTeam, $largeMember] = seedWizardClients(20, 'Wizlarge');

    $this->actingAsPerson($largeMember, $largeTeam);

    $large = countWizardQueries(
        fn () => $this->getJson('/deals/create/clients?q=Prospect')
            ->assertOk()
            ->assertJsonCount(20, 'people'),
    );

    expect($large)->toBe(
        $small,
        'The wizard’s client typeahead gained queries as the directory grew. '
        .'It fires on a 250ms debounce, so a per-row query is felt immediately.',
    );
});

it('does not grow the wizard’s property typeahead with the directory', function (): void {
    [$smallTeam, $smallMember] = seedWizardProperties(2, 'Alder');

    $this->actingAsPerson($smallMember, $smallTeam);

    $small = countWizardQueries(
        fn () => $this->getJson('/deals/create/properties?q=Alder')
            ->assertOk()
            ->assertJsonCount(2, 'properties'),
    );

    [$largeTeam, $largeMember] = seedWizardProperties(20, 'Birch');

    $this->actingAsPerson($largeMember, $largeTeam);

    $large = countWizardQueries(
        fn () => $this->getJson('/deals/create/properties?q=Birch')
            ->assertOk()
            ->assertJsonCount(20, 'properties'),
    );

    expect($large)->toBe(
        $small,
        'The wizard’s property typeahead gained queries as the directory grew. '
        .'`withCount(\'dealLinks\')` answers in one query; a per-row count is the '
        .'usual cause.',
    );
});
