<?php

declare(strict_types=1);

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\ActionDefinition;
use App\Models\DealType;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Templates\ImportPack;
use App\Support\Templates\PackFile;
use App\Support\Tenancy\TeamContext;
use Illuminate\Validation\ValidationException;

/**
 * A template pack as a file, out and back (#87 · #11).
 *
 * The loop this suite exists to hold: seed a draft pack → somebody marks it up
 * on S41 → export what they produced → that file is the pack that ships. Every
 * step of it is only worth having if the round trip is **lossless**, because
 * the point is that what Emily typed is what ships. A format whose two halves
 * drift loses a column silently, and the column it loses is the one nobody
 * thought to assert.
 *
 * So the central test compares a whole document against a whole document
 * rather than a handful of fields. A test naming the fields it checks is a
 * test that goes on passing when a new column is added to one half.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
});

/**
 * One pack, exercising every part of the format that is not the happy path.
 *
 * A milestone stage with client-facing wording and one without; a task that
 * gates an advance and one that does not; a gate that carries a configuration;
 * an automation with an execution mode that is not the default; and a deal
 * type association. Anything the format can say, this says.
 */
function seededPack(): TemplatePack
{
    return app(TeamContext::class)->runWithoutScope(function (): TemplatePack {
        $pack = TemplatePack::factory()->create([
            'name' => 'Buyer Representation',
            'slug' => 'buyer-representation',
            'description' => 'Emily’s buyer-side process.',
            'sort_order' => 1,
        ]);

        $template = WorkflowTemplate::factory()->create([
            'team_id' => null,
            'template_pack_id' => $pack->getKey(),
            'name' => 'Buyer Under Contract',
            'description' => 'Pre-contract through closing.',
        ]);

        $template->dealTypes()->attach(
            DealType::query()->whereNull('team_id')->where('name', 'Buyer Representation')->sole(),
            ['is_default' => true],
        );

        $under = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Under Contract',
            'description' => 'An offer is accepted and the clock is running.',
            'expected_duration_days' => 30,
            'owner_role' => 'Transaction coordinator',
            'is_milestone' => true,
            'client_facing_label' => 'You are under contract.',
            'sort_order' => 0,
        ]);

        $closing = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Pre-Closing',
            'owner_role' => null,
            'is_milestone' => false,
            'client_facing_label' => null,
            'sort_order' => 1,
        ]);

        GateTemplate::factory()->create([
            'stage_template_id' => $under->getKey(),
            'gate_type' => 'date_reached',
            'label' => 'Inspection objection has passed',
            'config' => ['keyDateName' => 'Inspection objection'],
            'is_blocking' => true,
            'sort_order' => 0,
        ]);

        GateTemplate::factory()->create([
            'stage_template_id' => $under->getKey(),
            'gate_type' => 'manual_confirmation',
            'label' => 'Appraisal received',
            'config' => null,
            'is_blocking' => false,
            'sort_order' => 1,
        ]);

        TaskTemplate::factory()->create([
            'stage_template_id' => $under->getKey(),
            'title' => 'Confirm loan application completed with lender',
            'description' => 'Ask for it in writing.',
            'owner_role' => 'Agent',
            'due_offset_days' => 3,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        TaskTemplate::factory()->create([
            'stage_template_id' => $under->getKey(),
            'title' => 'Bring client gift for inspection',
            'owner_role' => null,
            'due_offset_days' => null,
            'is_required' => false,
            'sort_order' => 1,
        ]);

        /*
         * A `create_task` automation and not a `send_email` one, and the
         * distinction is the pack's whole limit rather than a convenience:
         * `message_templates` is team-scoped and `action_definitions` is not,
         * so a CHECK constraint refuses a shared row naming a team's private
         * template. A shipped pack cannot carry the words it sends.
         */
        ActionDefinition::factory()->create([
            'team_id' => null,
            'stage_template_id' => $closing->getKey(),
            'trigger' => AutomationTrigger::StageStart,
            'action_type' => AutomationActionType::CreateTask,
            'config' => ['taskTitle' => 'Request the settlement statement'],
            'is_manual' => true,
            'requires_approval' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return $pack->refresh();
    });
}

it('writes a pack out and reads it back with nothing missing', function (): void {
    $document = PackFile::encodePack(seededPack());

    // Wiped rather than reimported beside itself, so what comes back is what
    // the file said and not what was already there.
    app(TeamContext::class)->runWithoutScope(function (): void {
        StageTemplate::query()->get()->each(fn (StageTemplate $stage) => $stage->forceDelete());
        WorkflowTemplate::query()->get()->each(fn (WorkflowTemplate $one) => $one->forceDelete());
        TemplatePack::query()->get()->each(fn (TemplatePack $one) => $one->forceDelete());
    });

    app(ImportPack::class)->asPack($document);

    $reimported = app(TeamContext::class)->runWithoutScope(
        fn (): TemplatePack => TemplatePack::query()->where('slug', 'buyer-representation')->sole(),
    );

    /*
     * The whole document against the whole document.
     *
     * Not a list of fields: a field list goes on passing when a column is
     * added to one half of `PackFile` and not the other, which is the exact
     * failure the one-class-both-directions design exists to prevent. If this
     * ever needs relaxing, the thing to relax is the format, not the test.
     */
    expect(PackFile::encodePack($reimported))->toBe($document);
});

it('keeps the order the file gives, not the order rows happen to come back in', function (): void {
    $document = PackFile::encodePack(seededPack());

    // Reverse the tasks in the file and import over the top.
    $document['workflows'][0]['stages'][0]['tasks'] = array_reverse(
        $document['workflows'][0]['stages'][0]['tasks'],
    );

    app(ImportPack::class)->asPack($document);

    $titles = app(TeamContext::class)->runWithoutScope(fn (): array => TaskTemplate::query()
        ->whereIn('stage_template_id', StageTemplate::query()
            ->whereIn('workflow_template_id', WorkflowTemplate::query()
                ->whereIn('template_pack_id', TemplatePack::query()
                    ->where('slug', 'buyer-representation')->pluck('id'))
                ->pluck('id'))
            ->where('name', 'Under Contract')
            ->pluck('id'))
        ->orderBy('sort_order')
        ->pluck('title')
        ->all());

    expect($titles)->toBe([
        'Bring client gift for inspection',
        'Confirm loan application completed with lender',
    ]);
});

it('rebuilds a pack rather than piling a second copy on top of it', function (): void {
    /*
     * The property that lets `TemplatePackSeeder` run on every deploy, which
     * is the same property `ReferenceDataSeeder` is held to one table over.
     */
    $document = PackFile::encodePack(seededPack());

    app(ImportPack::class)->asPack($document);
    app(ImportPack::class)->asPack($document);

    app(TeamContext::class)->runWithoutScope(function (): void {
        expect(TemplatePack::query()->where('slug', 'buyer-representation')->count())->toBe(1)
            ->and(WorkflowTemplate::query()->where('name', 'Buyer Under Contract')->count())->toBe(1);

        $stages = StageTemplate::query()
            ->whereIn('workflow_template_id', WorkflowTemplate::query()
                ->where('name', 'Buyer Under Contract')->pluck('id'))
            ->count();

        expect($stages)->toBe(2);

        // Force-deleted rather than soft-deleted on a rebuild: a soft delete
        // leaves a row the database's cascade never reaches, so ten deploys
        // would leave ten generations of dead stages under one template.
        expect(StageTemplate::query()->withTrashed()->count())->toBe(2);
    });
});

it('leaves a workflow the file stopped describing, and says so', function (): void {
    /*
     * `workflows.workflow_template_id` points at the row, so deleting it on a
     * re-seed costs a running deal its pointer. Reported instead — S39 already
     * has `is_active` for taking one out of circulation, which is the
     * reversible version of the same intention.
     */
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['name'] = 'Buyer Under Contract, revised';

    $report = app(ImportPack::class)->asPack($document);

    expect($report->templates)->toBe(['Buyer Under Contract, revised'])
        ->and(implode(' ', $report->notes))->toContain('Buyer Under Contract');

    app(TeamContext::class)->runWithoutScope(function (): void {
        expect(WorkflowTemplate::query()->where('name', 'Buyer Under Contract')->exists())->toBeTrue();
    });
});

it('refuses a pack whose automation names a message template', function (): void {
    /*
     * Refused in validation naming the constraint, rather than left to the
     * database. A CHECK violation surfaces as a `QueryException` naming a
     * constraint nobody has heard of, in the middle of a deploy.
     */
    $document = PackFile::encodePack(seededPack());

    $document['messageTemplates'] = [[
        'key' => 'under-contract',
        'name' => 'What to expect now that you are under contract',
        'channel' => 'email',
        'subject' => 'You are under contract',
        'bodyHtml' => '<p>Congratulations.</p>',
        'bodyText' => 'Congratulations.',
        'recipientRule' => ['type' => 'primary_contact'],
        'fromIdentity' => null,
    ]];
    $document['workflows'][0]['stages'][0]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => 'under-contract',
    ]];

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);

    app(TeamContext::class)->runWithoutScope(function (): void {
        // And nothing landed: the whole import is one transaction.
        expect(ActionDefinition::query()->where('action_type', 'send_email')->exists())->toBeFalse();
    });
});

it('carries an automation and its words into a team', function (): void {
    $document = PackFile::encodePack(seededPack());

    $document['messageTemplates'] = [[
        'key' => 'under-contract',
        'name' => 'What to expect now that you are under contract',
        'channel' => 'email',
        'subject' => 'You are under contract',
        'bodyHtml' => '<p>Congratulations.</p>',
        'bodyText' => 'Congratulations.',
        'recipientRule' => ['type' => 'primary_contact'],
        'fromIdentity' => null,
    ]];
    $document['workflows'][0]['stages'][0]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => 'under-contract',
    ]];

    $report = app(ImportPack::class)->intoTeam($document, $this->team);

    expect($report->templates)->toBe(['Buyer Under Contract']);

    app(TeamContext::class)->runFor($this->team, function (): void {
        $template = WorkflowTemplate::query()
            ->where('team_id', $this->team->getKey())
            ->where('name', 'Buyer Under Contract')
            ->sole();

        // The team's own, and not the pack's: a row still naming the pack is
        // one a future "update your packs" feature would try to reconcile.
        expect($template->template_pack_id)->toBeNull();

        $message = MessageTemplate::query()
            ->where('name', 'What to expect now that you are under contract')
            ->sole();

        $automation = ActionDefinition::query()
            ->where('action_type', 'send_email')
            ->sole();

        expect($automation->team_id)->toBe($this->team->getKey())
            ->and($automation->message_template_id)->toBe($message->getKey())
            ->and($automation->executionMode())->toBe('approval');
    });
});

it('refuses an automation whose action and template disagree about the channel', function (): void {
    $document = PackFile::encodePack(seededPack());

    $document['messageTemplates'] = [[
        'key' => 'a-push',
        'name' => 'A push, not an email',
        'channel' => 'push',
        'subject' => null,
        'bodyHtml' => null,
        'bodyText' => 'Something moved.',
        'recipientRule' => ['type' => 'team_owner'],
        'fromIdentity' => null,
    ]];
    $document['workflows'][0]['stages'][0]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => 'a-push',
    ]];

    expect(fn () => app(ImportPack::class)->intoTeam($document, $this->team))
        ->toThrow(ValidationException::class);
});

it('refuses an automation naming a message template the file does not carry', function (): void {
    // Refused rather than nulled. An automation that quietly lost its words is
    // one S44 shows as needing attention over a file that said otherwise.
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['stages'][0]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => 'nothing-defines-this',
    ]];

    expect(fn () => app(ImportPack::class)->intoTeam($document, $this->team))
        ->toThrow(ValidationException::class);
});

it('refuses a file written in a format it does not read', function (): void {
    $document = PackFile::encodePack(seededPack());
    $document['formatVersion'] = PackFile::VERSION + 1;

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);
});

it('accepts a gate type the picker cannot compose', function (): void {
    /*
     * `GateRegistry::types()` rather than `selectableOptions()`, and the
     * registry's own docblock asks for exactly this split: the narrow list is
     * *"what a person choosing from a dropdown may pick"*, because S43 has no
     * editor for five of the seven configurations. A file is written by
     * somebody who can supply one.
     */
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['stages'][0]['gates'][] = [
        'gateType' => 'document_present',
        'label' => 'Inspection report is on file',
        'isBlocking' => true,
        'config' => ['category' => 'inspection_report'],
    ];

    app(ImportPack::class)->asPack($document);

    app(TeamContext::class)->runWithoutScope(function (): void {
        $gate = GateTemplate::query()->where('gate_type', 'document_present')->sole();

        expect($gate->config)->toBe(['category' => 'inspection_report']);
    });
});

it('reports a deal type this install has not got rather than inventing one', function (): void {
    $document = PackFile::encodePack(seededPack());
    $document['workflows'][0]['dealTypes'] = [['name' => 'Commercial', 'isDefault' => false]];

    $report = app(ImportPack::class)->asPack($document);

    expect(implode(' ', $report->notes))->toContain('Commercial');

    app(TeamContext::class)->runWithoutScope(function (): void {
        // PRD §2.2 fixes the three, and commercial is deferred to a pack.
        expect(DealType::query()->whereNull('team_id')->where('name', 'Commercial')->exists())->toBeFalse();
    });
});

it('exports one team template as a pack file somebody can edit into a pack', function (): void {
    $template = app(TeamContext::class)->runFor($this->team, function (): WorkflowTemplate {
        $one = WorkflowTemplate::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => 'Listing to Close',
        ]);

        StageTemplate::factory()->create([
            'workflow_template_id' => $one->getKey(),
            'name' => 'Pre-listing',
            'sort_order' => 0,
        ]);

        return $one;
    });

    $document = PackFile::encodeTemplate($template);

    expect($document['formatVersion'])->toBe(PackFile::VERSION)
        // Derived from the template's own name so the file imports as it
        // stands. It is meant to be edited: a slug is a catalogue identity and
        // nobody's first template name is one.
        ->and($document['pack']['slug'])->toBe('listing-to-close')
        ->and($document['workflows'])->toHaveCount(1)
        ->and($document['workflows'][0]['name'])->toBe('Listing to Close');
});
