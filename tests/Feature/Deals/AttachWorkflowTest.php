<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\StageState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\StageTemplate;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;

/**
 * S28 — attach a workflow to a live deal (issue #74 · PRD F4.7).
 *
 * The third clause of #74's definition of done: *"attaching a second workflow
 * to a deal works and both run concurrently."*
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);

    $this->deal = app(TeamContext::class)->runFor($this->team, function (): Deal {
        $type = DealType::factory()->create(['team_id' => $this->team->getKey(), 'side' => DealSide::Sell]);

        return Deal::factory()->create(['team_id' => $this->team->getKey(), 'deal_type_id' => $type->getKey()]);
    });
});

function templateNamed(string $name, int $stages = 2, ?TemplatePack $pack = null): WorkflowTemplate
{
    $template = WorkflowTemplate::factory()->create([
        'team_id' => test()->team->getKey(),
        'template_pack_id' => $pack?->getKey(),
        'name' => $name,
        'is_active' => true,
    ]);

    foreach (range(1, $stages) as $position) {
        StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => "{$name} stage {$position}",
            'sort_order' => $position - 1,
        ]);
    }

    return $template;
}

it('runs two workflows on one deal concurrently', function (): void {
    /*
     * The definition of done, verbatim. PRD §7.5 corrected the rough data
     * model on exactly this: pre-listing improvements and the sale itself run
     * at the same time.
     */
    $listing = templateNamed('Selling a Property');
    $improvements = templateNamed('Pre-listing Improvements');

    foreach ([$listing, $improvements] as $template) {
        $this->post("/deals/{$this->deal->getKey()}/workflows", [
            'workflow_template_id' => $template->getKey(),
        ])->assertRedirect();
    }

    $workflows = $this->deal->workflows()->get();

    expect($workflows)->toHaveCount(2);

    // Both running, and each with its own first stage active.
    foreach ($workflows as $workflow) {
        expect($workflow->stages()->orderBy('sort_order')->first()->state)->toBe(StageState::Active);
    }
});

it('previews the stages a template would create, by name', function (): void {
    /*
     * Issue #74: *"the preview shows what will be created before it is
     * created. Attaching is not undoable in a tidy way."* A count would not
     * tell somebody the template is the wrong one; the names do.
     */
    templateNamed('Selling a Property', 3);

    $this->getJson("/deals/{$this->deal->getKey()}/workflows/available")
        ->assertOk()
        ->assertJsonPath('templates.0.name', 'Selling a Property')
        ->assertJsonCount(3, 'templates.0.stages')
        ->assertJsonPath('templates.0.stages.0.name', 'Selling a Property stage 1')
        ->assertJsonPath('templates.0.isAttached', false);
});

it('marks a template already on the deal rather than refusing it', function (): void {
    // `InstantiateWorkflow` is explicit that twice is allowed and means two
    // workflows — two rounds of pre-listing improvements is a real thing.
    $template = templateNamed('Selling a Property');

    $this->post("/deals/{$this->deal->getKey()}/workflows", [
        'workflow_template_id' => $template->getKey(),
    ])->assertRedirect();

    $this->getJson("/deals/{$this->deal->getKey()}/workflows/available")
        ->assertJsonPath('templates.0.isAttached', true);

    // And still attachable.
    $this->post("/deals/{$this->deal->getKey()}/workflows", [
        'workflow_template_id' => $template->getKey(),
    ])->assertRedirect();

    expect($this->deal->workflows()->count())->toBe(2);
});

it('filters the picker by pack', function (): void {
    $pack = TemplatePack::factory()->create(['name' => 'Listing', 'slug' => 'listing']);

    templateNamed('Selling a Property', 2, $pack);
    templateNamed('Something Else');

    $this->getJson("/deals/{$this->deal->getKey()}/workflows/available?pack=listing")
        ->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.name', 'Selling a Property')
        ->assertJsonPath('templates.0.packName', 'Listing');

    // And "all" is not a pack slug.
    $this->getJson("/deals/{$this->deal->getKey()}/workflows/available?pack=all")
        ->assertJsonCount(2, 'templates');
});

it('keeps an inactive template out of the picker and refuses it on the way in', function (): void {
    // A template a team took out of circulation is S76's archived deal type
    // one layer over: no new use of it, and the ones already running are
    // untouched.
    $template = templateNamed('Retired');
    $template->forceFill(['is_active' => false])->save();

    $this->getJson("/deals/{$this->deal->getKey()}/workflows/available")
        ->assertJsonCount(0, 'templates');

    $this->post("/deals/{$this->deal->getKey()}/workflows", [
        'workflow_template_id' => $template->getKey(),
    ])->assertSessionHasErrors('workflow_template_id');

    expect($this->deal->workflows()->count())->toBe(0);
});

it('starts the workflow on the day it is told to', function (): void {
    // The *Under Contract* workflow attaches when the offer is accepted, and
    // its dates run from then rather than from today.
    $template = templateNamed('Under Contract');

    $this->post("/deals/{$this->deal->getKey()}/workflows", [
        'workflow_template_id' => $template->getKey(),
        'starting_on' => '2026-09-01',
    ])->assertRedirect();

    // `planned_start`, which is the column the stage dates are counted from.
    expect($this->deal->workflows()->sole()->planned_start?->toDateString())->toBe('2026-09-01')
        // And the stages inherit it rather than starting today.
        ->and($this->deal->workflows()->sole()->stages()->orderBy('sort_order')
            ->first()->planned_start?->toDateString())->toBe('2026-09-01');
});
