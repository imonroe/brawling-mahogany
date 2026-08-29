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

/**
 * The round-1 review's findings, each with the case that exposed it.
 *
 * Kept together and named for the defect rather than the feature, because what
 * makes them worth having is not that the behaviour is right — the tests above
 * say that — but that these particular ways of being wrong all passed a green
 * suite once.
 */
it('sorts a configuration’s keys, so two exports of one pack are identical', function (): void {
    /*
     * `gate_templates.config` is jsonb, which does not preserve key order. The
     * round-trip test compares with `toBe` — order-sensitive — and passed only
     * because every configuration in its fixture happened to have one key. A
     * two-key configuration made a re-export a spurious diff and the "lossless"
     * claim untestable.
     */
    $pack = seededPack();

    app(TeamContext::class)->runWithoutScope(function (): void {
        $stage = StageTemplate::query()->where('name', 'Under Contract')->sole();

        GateTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'gate_type' => 'document_present',
            'label' => 'Inspection report is on file',
            // Written in an order that is not alphabetical, so a pass-through
            // would be visible.
            'config' => ['category' => 'inspection_report', 'attachedTo' => 'deal'],
            'sort_order' => 2,
        ]);
    });

    $document = PackFile::encodePack($pack->refresh());

    $gate = collect($document['workflows'][0]['stages'][0]['gates'])
        ->firstWhere('gateType', 'document_present');

    expect(array_keys($gate['config']))->toBe(['attachedTo', 'category']);

    app(ImportPack::class)->asPack($document);

    $reimported = app(TeamContext::class)->runWithoutScope(
        fn (): TemplatePack => TemplatePack::query()->where('slug', 'buyer-representation')->sole(),
    );

    expect(PackFile::encodePack($reimported))->toBe($document);
});

it('refuses two workflows in one file that answer to the same name', function (): void {
    /*
     * `packTemplate()` matches by name within the pack, so the second stanza
     * found the row the first had just written, overwrote it, and force-deleted
     * the first one's stages — while the report claimed two templates had been
     * written and `notes` was empty.
     */
    $document = PackFile::encodePack(seededPack());
    $document['workflows'][] = $document['workflows'][0];

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);
});

it('refuses a shared automation that sends words, named template or not', function (): void {
    /*
     * The refusal used to key on whether the file named a template. A
     * `send_email` with a null one satisfies the CHECK constraint, so it was
     * written: a shared automation `isComplete()` calls false, shipped to every
     * install on every deploy and copied into every team that installed the
     * pack. A row nothing can reach, seeded.
     */
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['stages'][0]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => null,
    ]];

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);

    app(TeamContext::class)->runWithoutScope(function (): void {
        expect(ActionDefinition::query()->where('action_type', 'send_email')->exists())->toBeFalse();
    });
});

it('refuses a gate whose configuration is an id from another install', function (): void {
    /*
     * `action_completed` stores an `actionDefinitionId`, and every import
     * rebuilds the automations with fresh ULIDs — the seeder runs on every
     * deploy — so the gate would arrive pointing at nothing and stay that way.
     * A blocking gate only an override can pass, built by a file.
     */
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['stages'][0]['gates'][] = [
        'gateType' => 'action_completed',
        'label' => 'The welcome email went out',
        'isBlocking' => true,
        'config' => ['actionDefinitionId' => '01JXQ0000000000000000000AA', 'label' => 'Welcome email'],
    ];

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);
});

it('refuses a collection written as a named object rather than a list', function (): void {
    /*
     * `array` is what JSON hands back for both `[…]` and `{…}`, and `rebuild()`
     * writes `sort_order` straight from the array key — so a keyed object put a
     * string into an `unsignedSmallInteger` and threw a `QueryException` at
     * whoever ran the deploy, from a seeder whose docblock promises a loud
     * failure rather than a confusing one.
     */
    $document = PackFile::encodePack(seededPack());

    $document['workflows'][0]['stages'] = ['first' => $document['workflows'][0]['stages'][0]];

    expect(fn () => app(ImportPack::class)->asPack($document))
        ->toThrow(ValidationException::class);
});

it('holds a message template to the rules the Messages screen holds one to', function (): void {
    /*
     * The import had its own thinner list, so a recipient rule the channel
     * cannot carry saved happily — and then threw `MalformedRecipientRule` out
     * of `MessageTemplateController::row()`, which runs for **every** template
     * on S45. One bad stanza in one pack file made a team's whole Messages
     * screen a 500, with no screen left to fix the row from.
     */
    $document = PackFile::encodePack(seededPack());
    $document['workflows'][0]['stages'][0]['automations'] = [];

    $stanza = [
        'key' => 'under-contract',
        'name' => 'What to expect now that you are under contract',
        'channel' => 'email',
        'subject' => 'You are under contract',
        'bodyHtml' => '<p>Congratulations.</p>',
        'bodyText' => 'Congratulations.',
        'recipientRule' => ['type' => 'primary_contact'],
        'fromIdentity' => null,
    ];

    /*
     * The control first. Without it every case below could be passing on some
     * other error in the document entirely, and the test's name — which is the
     * thing that stops anybody looking again — would be the only claim it made.
     */
    $document['messageTemplates'] = [$stanza];
    app(ImportPack::class)->intoTeam($document, $this->team);

    foreach ([
        // Each case names the **key** the refusal must land on, so a refusal
        // for some other reason fails the test rather than satisfying it.
        'messageTemplates.0.recipientRule.type' => ['recipientRule' => ['type' => 'not-a-real-rule']],
        // The address `email:rfc` accepts and `Symfony\Component\Mime\Address`
        // throws on — CLAUDE.md's own example, and the rule written for it was
        // sitting one directory away, unused.
        'messageTemplates.0.fromIdentity' => ['fromIdentity' => 'emily(work)@bosart.test'],
        // The dropped brace `MergeFields::strayBraceRuns()` exists for.
        'messageTemplates.0.bodyText' => ['bodyText' => 'Hello {{ client_first_name }.'],
        'messageTemplates.0.subject' => ['subject' => "You are\nunder contract"],
        // PRD §7.12: a channel with no transport can never leave the building.
        'messageTemplates.0.channel' => ['channel' => 'sms'],
        // A push template addressed to a client — F12.2 keeps push internal.
        'messageTemplates.0.recipientRule.type ' => [
            'channel' => 'push',
            'subject' => null,
            'bodyHtml' => null,
            'recipientRule' => ['type' => 'primary_contact'],
        ],
    ] as $key => $broken) {
        $document['messageTemplates'] = [[...$stanza, ...$broken]];

        try {
            app(ImportPack::class)->intoTeam($document, $this->team);

            expect(false)->toBeTrue(); // Reached only when nothing was refused.
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain(trim($key));
        }
    }
});

it('refuses two message templates in one file that fold to the same name', function (): void {
    /*
     * Not a duplicate row — a **wrong-words send**. The second stanza found the
     * first one's row through `liveTemplateNamed()`, so the automation the file
     * bound to the second sent the first one's subject and body to a client.
     * And the note printed about it said the *team* already had that template,
     * which was false and points the reader away from looking.
     */
    $document = PackFile::encodePack(seededPack());
    $document['workflows'][0]['stages'][0]['automations'] = [];

    $stanza = [
        'key' => 'a',
        'name' => 'Under contract',
        'channel' => 'email',
        'subject' => 'You are under contract',
        'bodyHtml' => null,
        'bodyText' => 'Hi there',
        'recipientRule' => ['type' => 'primary_contact'],
        'fromIdentity' => null,
    ];

    $document['messageTemplates'] = [
        $stanza,
        [...$stanza, 'key' => 'b', 'name' => 'under CONTRACT', 'bodyText' => 'Something else entirely'],
    ];

    try {
        app(ImportPack::class)->intoTeam($document, $this->team);

        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('messageTemplates.1.name');
    }
});

it('refuses an explicit null where the column has a default and forbids one', function (): void {
    /*
     * `PackFile::write()` keys on `array_key_exists`, so omitting a key takes
     * the default and writing `null` writes null — and eight rules said
     * `nullable` over a NOT NULL column. The result was a 23502 from Postgres
     * in the middle of a deploy, from a seeder whose docblock promises a
     * failure somebody can read.
     */
    $original = PackFile::encodePack(seededPack());

    foreach ([
        'workflows.0.stages.0.isMilestone' => ['stages', 'isMilestone'],
        'workflows.0.stages.0.gates.0.isBlocking' => ['gates', 'isBlocking'],
        'workflows.0.stages.0.tasks.0.isRequired' => ['tasks', 'isRequired'],
    ] as $key => [$collection, $field]) {
        $document = $original;

        if ($collection === 'stages') {
            $document['workflows'][0]['stages'][0][$field] = null;
        } else {
            $document['workflows'][0]['stages'][0][$collection][0][$field] = null;
        }

        try {
            app(ImportPack::class)->asPack($document);

            expect(false)->toBeTrue();
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain($key);
        }
    }

    // And omitting the key entirely still takes the column's own default,
    // which is the other half of the pair and the ordinary case.
    $document = $original;
    unset($document['workflows'][0]['stages'][0]['tasks'][0]['isRequired']);

    app(ImportPack::class)->asPack($document);

    app(TeamContext::class)->runWithoutScope(function (): void {
        expect(TaskTemplate::query()
            ->where('title', 'Confirm loan application completed with lender')
            ->sole()
            ->is_required)->toBeFalse();
    });
});

it('carries a message template’s every field out and back', function (): void {
    /*
     * The round trip above cannot reach this half: a pack's automations may
     * never name a message template, so `messageTemplates` is `[]` in the one
     * test that compares whole documents. Which left the stanza with the most
     * fields — and the only one whose contents reach a client — with no
     * losslessness guard at all: deleting `bodyHtml` from `MESSAGE_FIELDS` kept
     * the suite green.
     *
     * So this goes through a **team**, where the words are allowed, and
     * compares the stanza it gets back against the stanza it sent.
     */
    $document = PackFile::encodePack(seededPack());
    $document['workflows'][0]['stages'][0]['automations'] = [];

    $stanza = [
        'key' => 'under-contract',
        'name' => 'What to expect now that you are under contract',
        'channel' => 'email',
        'subject' => 'You are under contract',
        'bodyHtml' => '<p>Congratulations, {{ client_first_name }}.</p>',
        'bodyText' => 'Congratulations, {{ client_first_name }}.',
        'recipientRule' => ['type' => 'participant_role', 'participantRole' => 'buyer'],
        'fromIdentity' => 'emily@bosart.test',
    ];

    $document['messageTemplates'] = [$stanza];
    $document['workflows'][0]['stages'][1]['automations'] = [[
        'trigger' => 'stage_completion',
        'actionType' => 'send_email',
        'config' => [],
        'isActive' => true,
        'executionMode' => 'approval',
        'messageTemplate' => 'under-contract',
    ]];

    app(ImportPack::class)->intoTeam($document, $this->team);

    $exported = app(TeamContext::class)->runFor($this->team, fn (): array => PackFile::encodeTemplate(
        WorkflowTemplate::query()
            ->where('team_id', $this->team->getKey())
            ->where('name', 'Buyer Under Contract')
            ->sole(),
    ));

    /*
     * Whole stanza against whole stanza, for the reason the round trip above
     * gives: a list of fields goes on passing when a column is added to one
     * half of `PackFile` and not the other.
     *
     * Two things are deliberately **not** preserved, and both are the format
     * working rather than losing something:
     *
     *  - the `key` is derived from the name on the way out, because it is a
     *    label for a cross-reference within one file and not a stored value;
     *  - `recipientRule`'s keys come back sorted, because jsonb does not
     *    preserve key order and `PackFile::read()` fixes one so two exports of
     *    the same rows are identical.
     */
    expect($exported['messageTemplates'])->toBe([[
        ...$stanza,
        'key' => 'what-to-expect-now-that-you-are-under-contract',
        'recipientRule' => ['participantRole' => 'buyer', 'type' => 'participant_role'],
    ]]);

    // And the automation still names **the same template** on the way back
    // out — under the key the export derives, which is the point of the key
    // being derived: it re-joins the two halves of the file whatever the file
    // that came in called it.
    expect($exported['workflows'][0]['stages'][1]['automations'][0]['messageTemplate'])
        ->toBe($exported['messageTemplates'][0]['key']);
});

it('reuses a message template the team already has rather than colliding', function (): void {
    /*
     * `message_templates` carries a unique index over
     * `(team_id, channel, lower(name))` for live rows, so importing the same
     * file twice was an unhandled `UniqueConstraintViolationException` at the
     * operator — from a class whose docblock promised the opposite.
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

    app(ImportPack::class)->intoTeam($document, $this->team);
    $second = app(ImportPack::class)->intoTeam($document, $this->team);

    // Said out loud, because what will send is then the team's words and not
    // the file's.
    expect(implode(' ', $second->notes))->toContain('already had');

    app(TeamContext::class)->runFor($this->team, function (): void {
        $templates = MessageTemplate::query()
            ->where('name', 'What to expect now that you are under contract')
            ->get();

        expect($templates)->toHaveCount(1);

        // Both imports' automations point at the one template.
        expect(ActionDefinition::query()
            ->where('action_type', 'send_email')
            ->pluck('message_template_id')
            ->unique()
            ->all())->toBe([$templates->sole()->getKey()]);
    });
});

it('says when a pack with this slug was already here', function (): void {
    // The slug is the only thing matched on, so an unrelated file whose slug
    // collides rewrites a shipped pack. Reported rather than silent.
    $document = PackFile::encodePack(seededPack());

    expect(implode(' ', app(ImportPack::class)->asPack($document)->notes))
        ->toContain('already had a pack');
});

it('carries a gate-cleared automation by the gate’s label, not its id', function (): void {
    /*
     * The second identifier in this format, and the one a pack has a real use
     * for: *"when Survey received clears, create a task"*.
     *
     * `action_definitions.config.gateTemplateId` is a `gate_templates` ULID,
     * read by `InstantiateWorkflow::gateSortOrder()`. Every import rebuilds the
     * gates with fresh ids, so carrying it verbatim produced an automation that
     * **never fires, on every deal, forever** — and nothing says so, because
     * `isComplete()` only asks about message templates, so S41 shows no badge.
     *
     * Translated rather than refused, because the gate is on the automation's
     * own stage and so is in the same stanza. The label re-resolves, the way
     * `messageTemplate`'s key does.
     */
    $pack = seededPack();

    app(TeamContext::class)->runWithoutScope(function (): void {
        $stage = StageTemplate::query()->where('name', 'Under Contract')->sole();
        $gate = GateTemplate::query()->where('label', 'Appraisal received')->sole();

        ActionDefinition::factory()->create([
            'team_id' => null,
            'stage_template_id' => $stage->getKey(),
            'trigger' => AutomationTrigger::GateCleared,
            'action_type' => AutomationActionType::CreateTask,
            'config' => ['gateTemplateId' => $gate->getKey(), 'taskTitle' => 'Send the appraisal to the buyer'],
            'sort_order' => 0,
        ]);
    });

    $document = PackFile::encodePack($pack->refresh());
    $config = $document['workflows'][0]['stages'][0]['automations'][0]['config'];

    // No id in the file — a label, which is what a second install can read.
    expect($config)->toBe(['gateLabel' => 'Appraisal received', 'taskTitle' => 'Send the appraisal to the buyer']);

    app(ImportPack::class)->asPack($document);

    app(TeamContext::class)->runWithoutScope(function (): void {
        $automation = ActionDefinition::query()->where('trigger', 'gate_cleared')->sole();
        $gate = GateTemplate::query()
            ->where('stage_template_id', $automation->stage_template_id)
            ->where('label', 'Appraisal received')
            ->sole();

        // Re-resolved to the gate this import wrote, which is the whole point:
        // the id it arrived with belonged to another database.
        expect($automation->config['gateTemplateId'])->toBe($gate->getKey());
    });

    // And it survives a second pass, which is where the id version died: the
    // seeder runs on every deploy.
    app(ImportPack::class)->asPack($document);

    app(TeamContext::class)->runWithoutScope(function (): void {
        $automation = ActionDefinition::query()->where('trigger', 'gate_cleared')->sole();

        expect(GateTemplate::query()
            ->where('stage_template_id', $automation->stage_template_id)
            ->whereKey($automation->config['gateTemplateId'])
            ->exists())->toBeTrue();
    });
});

it('refuses an automation that could never do anything', function (): void {
    /*
     * `config` was `nullable|array` and nothing more, while
     * `SaveAutomationRequest` — the other writer — checks each of these. A
     * file could ship rows that fail per deal on every install and email the
     * team about each one through `automations:alert-on-failures`. The same
     * argument the shared-automation refusal already makes: *a row nothing can
     * reach, seeded*.
     */
    $original = PackFile::encodePack(seededPack());

    foreach ([
        'workflows.0.stages.0.automations.0.config.taskTitle' => [
            'trigger' => 'stage_start', 'actionType' => 'create_task', 'config' => [],
        ],
        'workflows.0.stages.0.automations.0.config.instruction' => [
            'trigger' => 'stage_start', 'actionType' => 'manual_prompt', 'config' => [],
        ],
        'workflows.0.stages.0.automations.0.config.gateLabel' => [
            'trigger' => 'gate_cleared', 'actionType' => 'create_task',
            'config' => ['taskTitle' => 'Do it', 'gateLabel' => 'No gate is called this'],
        ],
        // An action this build cannot carry out reaches `ExecuteAction`'s
        // `default` arm and fails for every deal — unlike a gate type, which
        // an evaluator can still answer if a file supplies its configuration.
        'workflows.0.stages.0.automations.0.actionType' => [
            'trigger' => 'stage_start', 'actionType' => 'send_push_notification', 'config' => [],
        ],
        // A trigger the enum has and nothing raises (#121, Slice 6).
        'workflows.0.stages.0.automations.0.trigger' => [
            'trigger' => 'post_closing_offset', 'actionType' => 'create_task',
            'config' => ['taskTitle' => 'Do it'],
        ],
    ] as $key => $automation) {
        $document = $original;

        $document['workflows'][0]['stages'][0]['automations'] = [[
            ...$automation,
            'isActive' => true,
            'executionMode' => 'automatic',
            'messageTemplate' => null,
        ]];

        try {
            app(ImportPack::class)->asPack($document);

            expect(false)->toBeTrue();
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain($key);
        }
    }
});
