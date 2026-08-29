<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\InstantiateWorkflow;

/**
 * S39–S43 — the templates UI (PRD F4.1, §7.1 · issues #84, #85, #86).
 *
 * The property that matters more than any screen here: **editing a template
 * must never change a deal already running.** PRD §7.1 calls the
 * template/instance split *"the highest-impact correction"* in the document,
 * and the first two tests are that sentence.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    // `templates.manage` belongs to Team Owner, not Team Member — IA §7's
    // separation showing up in the seeded roles.
    $owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole());

    $this->actingAsPerson($this->enrollTwoFactor($owner->person), $this->team);
});

function teamTemplate(string $name = 'Listing to Close'): WorkflowTemplate
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team, $name): WorkflowTemplate {
        $template = WorkflowTemplate::factory()->create([
            'team_id' => $team->getKey(),
            'name' => $name,
        ]);

        StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Listing Preparation',
            'sort_order' => 0,
        ]);

        return $template;
    });
}

function packTemplate(): WorkflowTemplate
{
    return app(TeamContext::class)->runWithoutScope(function (): WorkflowTemplate {
        $pack = TemplatePack::factory()->create();

        $template = WorkflowTemplate::factory()->create([
            'team_id' => null,
            'template_pack_id' => $pack->getKey(),
            'name' => 'Listing pack',
        ]);

        StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Pre-listing',
            'sort_order' => 0,
        ]);

        return $template;
    });
}

function runningDeal(WorkflowTemplate $template): Stage
{
    runningDealOn($template, test()->team);

    return app(TeamContext::class)->runFor(
        test()->team,
        fn (): Stage => Stage::query()->sole(),
    );
}

function runningDealOn(WorkflowTemplate $template, Team $team): void
{
    app(TeamContext::class)->runFor($team, function () use ($team, $template): void {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        app(InstantiateWorkflow::class)->handle($deal, $template);
    });
}

it('does not touch a deal already running when the template changes', function (): void {
    $template = teamTemplate();
    $stage = runningDeal($template);

    $stageTemplate = StageTemplate::query()->where('workflow_template_id', $template->getKey())->sole();

    $this->patch("/templates/{$template->getKey()}/stages/{$stageTemplate->getKey()}", [
        'name' => 'Renamed after the deal started',
    ])->assertRedirect();

    expect($stage->refresh()->name)->toBe('Listing Preparation');
});

it('leaves the running deal alone when a template stage is deleted', function (): void {
    $template = teamTemplate();
    runningDeal($template);

    $stageTemplate = StageTemplate::query()->where('workflow_template_id', $template->getKey())->sole();

    $this->delete("/templates/{$template->getKey()}/stages/{$stageTemplate->getKey()}")
        ->assertRedirect();

    expect(Stage::query()->count())->toBe(1);
});

it('refuses to edit a template every team shares', function (): void {
    /*
     * One pack is shared by every team, so one team's edit must not reach the
     * others — and the refusal has to hold all the way down. A guard on the
     * workflow row with a door beside it for its stages is not a guard.
     */
    $system = packTemplate();
    $stage = StageTemplate::query()->where('workflow_template_id', $system->getKey())->sole();

    $this->patch("/templates/{$system->getKey()}", ['name' => 'Mine now'])->assertForbidden();
    $this->post("/templates/{$system->getKey()}/stages", ['name' => 'Sneaking one in'])->assertForbidden();
    $this->delete("/templates/{$system->getKey()}/stages/{$stage->getKey()}")->assertForbidden();

    expect($system->refresh()->name)->toBe('Listing pack');
});

it('takes a deep copy, so editing the copy cannot reach the pack', function (): void {
    $system = packTemplate();

    $this->post("/templates/{$system->getKey()}/copy")->assertRedirect();

    $copy = WorkflowTemplate::query()->where('team_id', $this->team->getKey())->sole();

    expect($copy->name)->toContain('Listing pack')
        // Not the pack's any more: a row still naming it is a row a future
        // "update your packs" feature would try to reconcile.
        ->and($copy->template_pack_id)->toBeNull();

    $copied = StageTemplate::query()->where('workflow_template_id', $copy->getKey())->sole();
    $original = StageTemplate::query()->where('workflow_template_id', $system->getKey())->sole();

    expect($copied->getKey())->not->toBe($original->getKey());

    $this->patch("/templates/{$copy->getKey()}/stages/{$copied->getKey()}", ['name' => 'Ours now'])
        ->assertRedirect();

    expect($original->refresh()->name)->toBe('Pre-listing');
});

it('takes the whole stage order at once', function (): void {
    $template = teamTemplate();

    foreach (['Second', 'Third'] as $name) {
        $this->post("/templates/{$template->getKey()}/stages", ['name' => $name])->assertRedirect();
    }

    $ids = StageTemplate::query()
        ->where('workflow_template_id', $template->getKey())
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    $this->patch("/templates/{$template->getKey()}/stages", [
        'ids' => [$ids[2], $ids[0], $ids[1]],
    ])->assertRedirect();

    expect(StageTemplate::query()
        ->where('workflow_template_id', $template->getKey())
        ->orderBy('sort_order')
        ->pluck('id')
        ->all())->toBe([$ids[2], $ids[0], $ids[1]]);
});

it('holds a gate to the types the registry actually has', function (): void {
    /*
     * *"Adding a gate type means adding a class, not touching advancement
     * logic."* So the allowed values are read from the registry: an eighth
     * evaluator becomes selectable by existing, and a typo is a 422 rather
     * than a gate no evaluator will ever answer.
     */
    $template = teamTemplate();
    $stage = StageTemplate::query()->where('workflow_template_id', $template->getKey())->sole();

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'vibes_check',
        'label' => 'Feels right',
    ])->assertSessionHasErrors('gate_type');

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'manual_confirmation',
        'label' => 'Survey received',
    ])->assertRedirect();

    expect($stage->gateTemplates()->count())->toBe(1);
});

it('refuses a gate type this editor cannot fully specify', function (): void {
    /*
     * The picker offering all seven reintroduced, one layer up, the defect the
     * confirmation route removed at the runtime layer.
     *
     * Five of the seven evaluators read a `configuration` — a field name, a
     * role, a key date, a document category. `is_blocking` defaults to true,
     * so a gate composed without its configuration is a **permanently blocked
     * stage**: two clicks to build a stage only the audited exception can
     * pass.
     *
     * So the picker offers what S43 can specify, and the validation is held to
     * the same list — a request naming one of the others is refused rather
     * than quietly accepted.
     *
     * `date_reached` left the refused list with Slice 4 (#109): S43 now has a
     * field for the one thing it needs, which is the *name* of the key date.
     * That is what `EDITOR_CONFIGURABLE` records, and the case below asserts
     * the other half — offering the type without asking for its date would be
     * the same permanently-blocked stage by a shorter route.
     */
    $template = teamTemplate();
    $stage = StageTemplate::query()->where('workflow_template_id', $template->getKey())->sole();

    foreach (['document_present', 'field_populated', 'approval', 'action_completed'] as $type) {
        $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
            'gate_type' => $type,
            'label' => 'Composed without its configuration',
        ])->assertSessionHasErrors('gate_type');
    }

    expect($stage->gateTemplates()->count())->toBe(0);

    /*
     * And the two that clear on their own are offered, which is the control:
     * a validation rule refusing everything would pass the loop above.
     *
     * Distinct labels, because #87 made a gate's label unique on its stage —
     * a `gate_cleared` automation names its gate by label in a pack file, and
     * two with one name cannot be told apart.
     */
    foreach (['manual_confirmation', 'required_tasks_complete'] as $index => $type) {
        $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
            'gate_type' => $type,
            'label' => 'Something somebody can clear '.$index,
        ])->assertRedirect();
    }

    expect($stage->gateTemplates()->count())->toBe(2);

    /*
     * And the one type this editor *can* configure is refused without its
     * configuration and accepted with it. Offering a type whose configuration
     * the form never asks for would be the permanently-blocked stage again,
     * one layer down from the picker.
     */
    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'date_reached',
        'label' => 'Objection period has run',
    ])->assertSessionHasErrors('config.keyDateName');

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/gates", [
        'gate_type' => 'date_reached',
        'label' => 'Objection period has run',
        'config' => ['keyDateName' => 'Inspection objection'],
    ])->assertRedirect();

    expect($stage->gateTemplates()->where('gate_type', 'date_reached')->sole()->config)
        ->toBe(['keyDateName' => 'Inspection objection']);

    /*
     * The page offers exactly those three, and can still *name* all seven — a
     * gate a pack carries has to render with its name whether or not this
     * screen could have built it.
     */
    $this->get("/templates/{$template->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gateTypes', [
                'manual_confirmation' => 'Manual confirmation',
                'required_tasks_complete' => 'Required tasks complete',
                'date_reached' => 'Date reached',
            ])
            ->where('gateTypeLabels.document_present', 'Document present'));
});

it('shows another team none of this one’s templates', function (): void {
    teamTemplate('Mine');

    [$otherTeam] = $this->teamWithMember();

    $otherOwner = TeamMembership::withoutTeamScope()
        ->where('team_id', $otherTeam->getKey())
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole();

    $this->actingAsPerson($this->enrollTwoFactor($otherOwner->person), $otherTeam);

    $this->get('/templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('templates', []));
});

it('counts only this team’s deals as running on a shared template', function (): void {
    /*
     * The number is shown *before* an edit, and its direction is the
     * reassuring one — those deals will not change, because instantiation
     * snapshotted. But a **pack** template is shared by every team, so a count
     * taken without the scope would tell one team how many deals every other
     * team is running. Counting the wrong thing here is a tenancy leak wearing
     * a helpful number.
     */
    $system = packTemplate();

    runningDealOn($system, test()->team);

    [$otherTeam] = $this->teamWithMember();
    runningDealOn($system, $otherTeam);

    $this->get("/templates/{$system->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('template.inUse', 1));
});

it('renames a template and removes one, from the screen that owns it', function (): void {
    /*
     * Both routes existed with nothing calling them — a screen that can build
     * a process and never correct its name. *"Listing to Close (copy)"* is
     * what taking a copy from a pack produces, so renaming is the first thing
     * somebody does after arriving here.
     */
    $template = teamTemplate();

    $this->patch("/templates/{$template->getKey()}", [
        'name' => 'Our Listing Process',
        'description' => 'How we actually do it.',
    ])->assertRedirect();

    expect($template->refresh()->name)->toBe('Our Listing Process');

    $this->delete("/templates/{$template->getKey()}")->assertRedirect();

    // Soft, for PRD §9's window — and the deals running on it are untouched,
    // which is the whole point of the snapshot.
    expect(WorkflowTemplate::query()->find($template->getKey()))->toBeNull()
        ->and(WorkflowTemplate::withTrashed()->find($template->getKey()))->not->toBeNull();
});

it('edits a composed role without touching a shipped one', function (): void {
    /*
     * `roles.update` had no caller either. Composing a role you can never
     * adjust is half a feature: the first thing anybody does after building
     * "Transaction Coordinator" is discover it needs one more permission.
     */
    $this->post('/settings/roles', [
        'name' => 'Transaction Coordinator',
        'permissions' => [App\Support\Permissions::VIEW_DEALS],
    ])->assertRedirect();

    $role = app(TeamContext::class)->runFor(
        test()->team,
        fn () => App\Models\Role::query()->where('key', 'transaction_coordinator')->sole(),
    );

    $this->patch("/settings/roles/{$role->getKey()}", [
        'name' => 'Transaction Coordinator',
        'permissions' => [
            App\Support\Permissions::VIEW_DEALS,
            App\Support\Permissions::MANAGE_DEALS,
        ],
    ])->assertRedirect();

    expect($role->fresh()->permissionKeys())->toEqualCanonicalizing([
        App\Support\Permissions::VIEW_DEALS,
        App\Support\Permissions::MANAGE_DEALS,
    ]);
});
