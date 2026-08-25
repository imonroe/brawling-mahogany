<?php

declare(strict_types=1);

use App\Enums\MessageChannel;
use App\Enums\RecipientRuleType;
use App\Models\ActionDefinition;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;

/**
 * A team's words to its own clients stay its own (issue #90, ADR 0002).
 *
 * `message_templates` is the one table in the definition layer that is fully
 * team-scoped — the migration argues why at length — so these are the ordinary
 * five-layer assertions, plus the two that are specific to this table:
 *
 *  - **The in-use count is scoped.** `WorkflowTemplate::inUseCount()` records
 *    what an unscoped count off a shared row costs; this one is scoped by
 *    construction and the test is what says so.
 *  - **A preview takes a deal id from a request body**, which
 *    `CrossTenantAccessTest` names as the "foreign id in a form" vector.
 */
beforeEach(function (): void {
    $this->teams = collect(['a', 'b'])->mapWithKeys(function (string $key): array {
        [$team] = $this->teamWithMember();

        $owner = app(TeamContext::class)->runFor($team, fn (): TeamMembership => TeamMembership::query()
            ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
            ->sole());

        return [$key => ['team' => $team, 'owner' => $owner]];
    });
});

function templateIn(string $key, array $attributes = []): MessageTemplate
{
    $team = test()->teams[$key]['team'];

    return app(TeamContext::class)->runFor(
        $team,
        fn (): MessageTemplate => MessageTemplate::factory()->create([
            'team_id' => $team->getKey(),
            ...$attributes,
        ]),
    );
}

function signInAs(string $key): void
{
    $context = test()->teams[$key];

    test()->actingAsPerson(test()->enrollTwoFactor($context['owner']->person), $context['team']);
}

it('does not list another team’s templates', function (): void {
    templateIn('a', ['name' => 'A’s words']);
    templateIn('b', ['name' => 'B’s words']);

    signInAs('a');

    // The control first: the row that must not be seen has to exist, or this
    // passes on an empty table.
    expect(MessageTemplate::withoutTeamScope()->count())->toBe(2);

    $this->get('/templates/messages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('templates', 1)
            ->where('templates.0.name', 'A’s words'));
});

it('answers 404 rather than 403 for another team’s template', function (): void {
    // A 403 confirms the record exists (ADR 0002, layer 3).
    $foreign = templateIn('b');

    signInAs('a');

    $this->get("/templates/messages/{$foreign->getKey()}")->assertNotFound();
    $this->patch("/templates/messages/{$foreign->getKey()}", [
        'name' => 'Taken over',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertNotFound();
    $this->post("/templates/messages/{$foreign->getKey()}/archive")->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('Taken over');
});

it('counts only the asking team’s automations', function (): void {
    /*
     * Every message template belongs to one team, so the count cannot leak the
     * way a shared row's can — but the assertion is here anyway, because
     * "cannot" is a property of the current schema rather than a guarantee,
     * and this is the shape `WorkflowTemplate::inUseCount()` got wrong.
     */
    $template = templateIn('a');

    $automationsFor = function (string $key, string $messageTemplateId, int $count): void {
        $team = test()->teams[$key]['team'];

        app(TeamContext::class)->runFor($team, function () use ($team, $messageTemplateId, $count): void {
            $stage = StageTemplate::factory()->create([
                'workflow_template_id' => WorkflowTemplate::factory()
                    ->create(['team_id' => $team->getKey()])->getKey(),
            ]);

            ActionDefinition::factory()->sendingEmail()->count($count)->create([
                'team_id' => $team->getKey(),
                'stage_template_id' => $stage->getKey(),
                'message_template_id' => $messageTemplateId,
            ]);
        });
    };

    $automationsFor('a', $template->getKey(), 2);

    signInAs('a');

    $this->get('/templates/messages')
        ->assertInertia(fn ($page) => $page->where('templates.0.inUse', 2));
});

it('refuses a preview against another team’s deal', function (): void {
    $template = templateIn('a');

    $foreignDeal = app(TeamContext::class)->runFor(
        $this->teams['b']['team'],
        fn (): Deal => Deal::factory()->create(['team_id' => $this->teams['b']['team']->getKey()]),
    );

    signInAs('a');

    // The deal id arrives in a request body with nothing in the URL to scope
    // it, which is exactly the vector the isolation suite enumerates.
    $this->post("/templates/messages/{$template->getKey()}/preview", [
        'deal' => $foreignDeal->getKey(),
        'body_text' => 'x',
    ])->assertNotFound();
});

it('refuses to point one team’s automation at another team’s template', function (): void {
    $foreign = templateIn('b');

    $team = $this->teams['a']['team'];

    [$template, $stage] = app(TeamContext::class)->runFor($team, function () use ($team): array {
        $workflow = WorkflowTemplate::factory()->create(['team_id' => $team->getKey()]);

        return [$workflow, StageTemplate::factory()->create([
            'workflow_template_id' => $workflow->getKey(),
        ])];
    });

    signInAs('a');

    $this->post("/templates/{$template->getKey()}/stages/{$stage->getKey()}/automations", [
        'trigger' => 'stage_start',
        'action_type' => 'send_email',
        'message_template_id' => $foreign->getKey(),
        'executionMode' => 'automatic',
    ])->assertSessionHasErrors('message_template_id');

    expect(ActionDefinition::query()->count())->toBe(0);
});
