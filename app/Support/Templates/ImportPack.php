<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\MessageChannel;
use App\Models\ActionDefinition;
use App\Models\DealType;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\Gates\GateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A pack file, turned into rows (#87 · #11).
 *
 * The writing half of {@see PackFile}. Two destinations, and they are
 * genuinely different operations rather than one with a flag:
 *
 *  - **`asPack()`** writes the catalogue — `team_id` null, shared by every
 *    install, upserted on the pack's slug so the seeder can run on every
 *    deploy the way `DealTypeSeeder` does.
 *  - **`intoTeam()`** writes one team's own templates — `team_id` set, no
 *    pack, always new rows. Importing twice gives two templates, which is what
 *    copying twice does and what somebody importing a file means.
 *
 * ## A pack cannot carry the words it sends, and the refusal is here
 *
 * `message_templates` is strictly team-scoped and `action_definitions` is not,
 * so the migration carries `CHECK (team_id IS NOT NULL OR message_template_id
 * IS NULL)` — a system automation may never name a team's private template.
 * That is a real product limit on #87 rather than a gap in this class: a
 * shipped pack can carry a *create task* automation and cannot carry a *send
 * email* one.
 *
 * It is refused **in validation**, naming the constraint, rather than left to
 * the database. A CHECK violation surfaces as a `QueryException` naming a
 * constraint nobody has heard of, in the middle of a deploy.
 *
 * ## The file is the pack, except where the file cannot say so
 *
 * Re-importing rebuilds each named workflow's stages outright, because a pack
 * file is the definition and half-merging two definitions produces one nobody
 * wrote. What it does **not** do is delete a workflow template the file no
 * longer names: `workflows.workflow_template_id` points at it, and a running
 * deal losing that pointer to a re-seed is a cost no file edit should be able
 * to impose silently. The row is left and reported — S39 already has
 * `is_active` for taking one out of circulation, which is the reversible
 * version of the same intention.
 *
 * ## Order comes from position
 *
 * `sort_order` is written from the array index, never read from the file. See
 * {@see PackFile} for why the file has no ordering numbers to disagree with.
 */
final class ImportPack
{
    public function __construct(private readonly TeamContext $teams) {}

    /**
     * Write the document into the shared catalogue.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws ValidationException
     */
    public function asPack(array $document): ImportReport
    {
        $document = $this->validated($document, forPack: true);

        /** @var array<string, mixed> $stanza */
        $stanza = $document['pack'];
        $notes = [];

        /** @var ImportReport */
        return DB::transaction(function () use ($document, $stanza, &$notes): ImportReport {
            $pack = TemplatePack::query()->withTrashed()->firstOrNew(['slug' => $stanza['slug']]);

            $pack->forceFill(PackFile::packColumns($stanza));
            $pack->deleted_at = null;
            $pack->save();

            $names = [];

            /** @var list<array<string, mixed>> $workflows */
            $workflows = $document['workflows'];

            foreach ($workflows as $workflow) {
                $template = $this->packTemplate($pack, $workflow);

                $this->rebuild($template, $workflow, teamId: null, messages: [], notes: $notes);

                $names[] = $template->name;
            }

            foreach ($this->orphaned($pack, $names) as $left) {
                $notes[] = "The pack still holds “{$left}”, which this file does not describe. "
                    .'It was left alone — a running deal points at it. Deactivate it on the templates screen if it is finished with.';
            }

            return new ImportReport(pack: $pack->slug, templates: $names, notes: $notes);
        });
    }

    /**
     * Write the document into one team, as templates that team owns.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws ValidationException
     */
    public function intoTeam(array $document, Team $team): ImportReport
    {
        $document = $this->validated($document, forPack: false);

        /** @var array<string, mixed> $stanza */
        $stanza = $document['pack'];
        $notes = [];

        /** @var ImportReport */
        return $this->teams->runFor($team, fn (): ImportReport => DB::transaction(
            function () use ($document, $stanza, $team, &$notes): ImportReport {
                $messages = $this->writeMessageTemplates($document, $notes);

                $names = [];

                /** @var list<array<string, mixed>> $workflows */
                $workflows = $document['workflows'];

                foreach ($workflows as $workflow) {
                    $template = new WorkflowTemplate;

                    $template->forceFill(PackFile::workflowColumns($workflow) + [
                        'team_id' => $team->getKey(),
                        // Not the pack's, for `CopyTemplate`'s reason: a row
                        // still naming the pack is one a future "update your
                        // packs" feature would try to reconcile, and this one
                        // is the team's to diverge from the moment it lands.
                        'template_pack_id' => null,
                    ])->save();

                    $this->rebuild($template, $workflow, teamId: $team->getKey(), messages: $messages, notes: $notes);

                    $names[] = $template->name;
                }

                return new ImportReport(pack: (string) $stanza['slug'], templates: $names, notes: $notes);
            },
        ));
    }

    /**
     * The pack's copy of one workflow, found by name or created.
     *
     * Name is the identity for the same reason it is in `DealTypeSeeder`: a
     * ULID from whichever database wrote the file means nothing here, and a
     * pack that renamed a workflow between releases has authored a new one as
     * far as any install can tell.
     *
     * @param  array<string, mixed>  $stanza
     */
    private function packTemplate(TemplatePack $pack, array $stanza): WorkflowTemplate
    {
        $template = WorkflowTemplate::query()
            ->withTrashed()
            ->where('template_pack_id', $pack->getKey())
            ->whereNull('team_id')
            ->where('name', $stanza['name'])
            ->first() ?? new WorkflowTemplate;

        $template->forceFill(PackFile::workflowColumns($stanza) + [
            'team_id' => null,
            'template_pack_id' => $pack->getKey(),
        ]);
        $template->deleted_at = null;
        $template->save();

        return $template;
    }

    /**
     * Names of the pack's workflows this file did not describe.
     *
     * @param  list<string>  $named
     * @return list<string>
     */
    private function orphaned(TemplatePack $pack, array $named): array
    {
        return array_values(WorkflowTemplate::query()
            ->where('template_pack_id', $pack->getKey())
            ->whereNotIn('name', $named)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    /**
     * Replace a template's stages, and everything hanging off them.
     *
     * Force-deleted rather than soft-deleted, and the distinction matters
     * twice. A soft delete leaves the row for the database's cascade to miss,
     * so re-importing a pack ten times would accumulate ten generations of
     * dead stages under one template. And the parent's hard delete is what
     * `ON DELETE CASCADE` is waiting for — it takes the gates, the tasks and
     * the automations with it, including any already soft-deleted.
     *
     * Nothing outside the definition layer is reachable from here:
     * `workflows` holds a snapshot and no pointer back, and
     * `action_instances.action_definition_id` is a bare nullable column with
     * no foreign key precisely so a deleted definition cannot delete the
     * record of a message that has already gone to a client.
     *
     * @param  array<string, mixed>  $stanza
     * @param  array<string, string>  $messages  file key => message template id
     * @param  list<string>  $notes
     */
    private function rebuild(
        WorkflowTemplate $template,
        array $stanza,
        ?string $teamId,
        array $messages,
        array &$notes,
    ): void {
        StageTemplate::query()
            ->where('workflow_template_id', $template->getKey())
            ->withTrashed()
            ->get()
            ->each(fn (StageTemplate $stage) => $stage->forceDelete());

        $this->attachDealTypes($template, $stanza, $teamId, $notes);

        /** @var list<array<string, mixed>> $stages */
        $stages = $stanza['stages'] ?? [];

        foreach ($stages as $position => $stage) {
            $row = new StageTemplate;

            $row->forceFill(PackFile::stageColumns($stage) + [
                'workflow_template_id' => $template->getKey(),
                'sort_order' => $position,
            ])->save();

            /** @var list<array<string, mixed>> $gates */
            $gates = $stage['gates'] ?? [];

            foreach ($gates as $order => $gate) {
                (new GateTemplate)->forceFill(PackFile::gateColumns($gate) + [
                    'stage_template_id' => $row->getKey(),
                    'sort_order' => $order,
                ])->save();
            }

            /** @var list<array<string, mixed>> $tasks */
            $tasks = $stage['tasks'] ?? [];

            foreach ($tasks as $order => $task) {
                (new TaskTemplate)->forceFill(PackFile::taskColumns($task) + [
                    'stage_template_id' => $row->getKey(),
                    'sort_order' => $order,
                ])->save();
            }

            /** @var list<array<string, mixed>> $automations */
            $automations = $stage['automations'] ?? [];

            foreach ($automations as $order => $automation) {
                $key = $automation['messageTemplate'] ?? null;

                (new ActionDefinition)->forceFill(PackFile::automationColumns($automation) + [
                    'stage_template_id' => $row->getKey(),
                    // Mirrors the parent template's, which is what the
                    // composite foreign key and the CHECK constraint both
                    // read. Null here is what makes a pack row shared.
                    'team_id' => $teamId,
                    'message_template_id' => is_string($key) ? ($messages[$key] ?? null) : null,
                    'sort_order' => $order,
                ])->save();
            }
        }
    }

    /**
     * Associate the workflow with the deal types the file names.
     *
     * By name, because the three system types are fixed by PRD §2.2 and
     * `DealTypeSeeder` already treats the name as the identity. A name this
     * install has not got is reported rather than created: inventing a deal
     * type from a pack file would put a lookup in front of every team that
     * nobody chose.
     *
     * @param  array<string, mixed>  $stanza
     * @param  list<string>  $notes
     */
    private function attachDealTypes(
        WorkflowTemplate $template,
        array $stanza,
        ?string $teamId,
        array &$notes,
    ): void {
        /** @var list<array<string, mixed>> $wanted */
        $wanted = $stanza['dealTypes'] ?? [];

        $attach = [];

        foreach ($wanted as $each) {
            $name = (string) ($each['name'] ?? '');

            $type = DealType::query()
                ->where('name', $name)
                ->where(fn ($query) => $teamId === null
                    ? $query->whereNull('team_id')
                    : $query->whereNull('team_id')->orWhere('team_id', $teamId))
                ->first();

            if (! $type instanceof DealType) {
                $notes[] = "“{$template->name}” names the deal type “{$name}”, which this install has not got. "
                    .'The workflow was imported without that association.';

                continue;
            }

            $attach[$type->getKey()] = ['is_default' => (bool) ($each['isDefault'] ?? false)];
        }

        $template->dealTypes()->sync($attach);
    }

    /**
     * Write the file's message templates into the current team.
     *
     * Always created, never matched to an existing template of the same name.
     * Reuse would bind an imported automation to words somebody else wrote and
     * may since have changed — the automation would look right on S44 and send
     * something the file never described. A duplicate name is visible on S45
     * and fixable there; a silently rebound automation is neither.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $notes
     * @return array<string, string> file key => message template id
     */
    private function writeMessageTemplates(array $document, array &$notes): array
    {
        /** @var list<array<string, mixed>> $stanzas */
        $stanzas = $document['messageTemplates'] ?? [];

        $written = [];

        foreach ($stanzas as $stanza) {
            $template = new MessageTemplate;

            // `fill`, not `forceFill`: `BelongsToTeam` puts `team_id` on from
            // the resolved team, and the trait's own rule is that nothing else
            // ever writes it.
            $template->fill(PackFile::messageColumns($stanza));
            $template->save();

            $written[(string) $stanza['key']] = (string) $template->getKey();
        }

        if ($written !== []) {
            $notes[] = count($written).' message '.(count($written) === 1 ? 'template was' : 'templates were')
                .' created for this team. Read them on Templates → Messages before anything sends.';
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $document, bool $forPack): array
    {
        /*
         * Read before validation, so nothing here may assume a shape: this is
         * the raw document, and the whole point of the next few lines is to
         * find the keys an automation is allowed to name. Anything malformed
         * is skipped here and refused by the rules.
         */
        $messages = $this->rawMessages($document);

        $keys = array_values(array_filter(array_map(
            fn (mixed $each): ?string => is_array($each) && is_string($each['key'] ?? null) ? $each['key'] : null,
            $messages,
        )));

        $validator = Validator::make($document, $this->rules($keys), $this->errorMessages());

        $validator->after(function ($validator) use ($document, $forPack): void {
            $this->checkAutomations($validator, $document, $forPack);
        });

        /** @var array<string, mixed> */
        return $validator->validate();
    }

    /**
     * @param  list<string>  $messageKeys
     * @return array<string, mixed>
     */
    private function rules(array $messageKeys): array
    {
        $stage = 'workflows.*.stages.*';

        return [
            /*
             * Refused rather than assumed, because the fields a later format
             * adds are exactly the ones this importer would drop — and a pack
             * that lost half its metadata on the way in looks like a pack
             * somebody wrote badly.
             */
            'formatVersion' => ['required', 'integer', Rule::in([PackFile::VERSION])],

            'pack' => ['required', 'array'],
            'pack.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'pack.name' => ['required', 'string', 'max:255'],
            'pack.description' => ['nullable', 'string', 'max:2000'],
            'pack.isInstalledByDefault' => ['nullable', 'boolean'],
            'pack.priceTier' => ['nullable', 'string', 'max:255'],
            'pack.sortOrder' => ['nullable', 'integer', 'between:0,65535'],

            'messageTemplates' => ['nullable', 'array'],
            'messageTemplates.*.key' => ['required', 'string', 'max:255', 'distinct'],
            'messageTemplates.*.name' => ['required', 'string', 'max:255'],
            'messageTemplates.*.channel' => ['required', Rule::in(array_column(MessageChannel::cases(), 'value'))],
            'messageTemplates.*.subject' => ['nullable', 'string', 'max:255'],
            'messageTemplates.*.bodyHtml' => ['nullable', 'string'],
            // NOT NULL in the schema: Design System §12 wants a real
            // plain-text half of every message, not a stripped-tags fallback.
            'messageTemplates.*.bodyText' => ['required', 'string'],
            'messageTemplates.*.recipientRule' => ['required', 'array'],
            'messageTemplates.*.recipientRule.type' => ['required', 'string'],
            'messageTemplates.*.fromIdentity' => ['nullable', 'string', 'max:255'],

            'workflows' => ['required', 'array', 'min:1'],
            'workflows.*.name' => ['required', 'string', 'max:120'],
            'workflows.*.description' => ['nullable', 'string', 'max:2000'],
            'workflows.*.version' => ['nullable', 'integer', 'min:1'],
            'workflows.*.isActive' => ['nullable', 'boolean'],
            'workflows.*.dealTypes' => ['nullable', 'array'],
            'workflows.*.dealTypes.*.name' => ['required', 'string', 'max:255'],
            'workflows.*.dealTypes.*.isDefault' => ['nullable', 'boolean'],

            'workflows.*.stages' => ['nullable', 'array'],
            $stage.'.name' => ['required', 'string', 'max:120'],
            $stage.'.description' => ['nullable', 'string', 'max:2000'],
            $stage.'.expectedDurationDays' => ['nullable', 'integer', 'between:0,365'],
            $stage.'.ownerRole' => ['nullable', 'string', 'max:120'],
            $stage.'.isMilestone' => ['nullable', 'boolean'],
            $stage.'.clientFacingLabel' => ['nullable', 'string', 'max:160'],

            $stage.'.gates' => ['nullable', 'array'],
            /*
             * `types()`, not `selectableOptions()`, and the registry's own
             * docblock asks for exactly this: the narrow list is *"what a
             * person choosing from a dropdown may pick"*, because S43 has no
             * editor for five of the seven configurations. A file is written
             * by somebody who can supply one, so a pack may carry a
             * `document_present` gate that the picker cannot yet compose.
             */
            $stage.'.gates.*.gateType' => ['required', Rule::in(GateRegistry::types())],
            $stage.'.gates.*.label' => ['required', 'string', 'max:120'],
            $stage.'.gates.*.isBlocking' => ['nullable', 'boolean'],
            $stage.'.gates.*.config' => ['nullable', 'array'],

            $stage.'.tasks' => ['nullable', 'array'],
            $stage.'.tasks.*.title' => ['required', 'string', 'max:200'],
            $stage.'.tasks.*.description' => ['nullable', 'string', 'max:2000'],
            $stage.'.tasks.*.ownerRole' => ['nullable', 'string', 'max:120'],
            $stage.'.tasks.*.dueOffsetDays' => ['nullable', 'integer', 'between:-365,365'],
            $stage.'.tasks.*.isRequired' => ['nullable', 'boolean'],

            $stage.'.automations' => ['nullable', 'array'],
            $stage.'.automations.*.trigger' => ['required', Rule::in(array_column(AutomationTrigger::cases(), 'value'))],
            $stage.'.automations.*.actionType' => ['required', Rule::in(array_column(AutomationActionType::cases(), 'value'))],
            $stage.'.automations.*.executionMode' => ['nullable', Rule::in(['automatic', 'approval', 'manual'])],
            $stage.'.automations.*.isActive' => ['nullable', 'boolean'],
            $stage.'.automations.*.config' => ['nullable', 'array'],
            // Named, not numbered — the file's own key for a message template
            // it also carries. A dangling key is refused rather than nulled:
            // an automation that quietly lost its words is one S44 shows as
            // needing attention over a file that said otherwise.
            $stage.'.automations.*.messageTemplate' => ['nullable', 'string', Rule::in($messageKeys)],
        ];
    }

    /**
     * The pairings a rule list cannot express, checked together so one run
     * reports all of them.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $document
     */
    private function checkAutomations(mixed $validator, array $document, bool $forPack): void
    {
        $channels = [];

        $messages = $this->rawMessages($document);

        foreach ($messages as $each) {
            if (is_array($each) && is_string($each['key'] ?? null)) {
                $channels[$each['key']] = MessageChannel::tryFrom((string) ($each['channel'] ?? ''));
            }
        }

        /** @var list<array<string, mixed>> $workflows */
        $workflows = is_array($document['workflows'] ?? null) ? $document['workflows'] : [];

        foreach ($workflows as $w => $workflow) {
            /** @var list<array<string, mixed>> $stages */
            $stages = is_array($workflow['stages'] ?? null) ? $workflow['stages'] : [];

            foreach ($stages as $s => $stage) {
                /** @var list<array<string, mixed>> $automations */
                $automations = is_array($stage['automations'] ?? null) ? $stage['automations'] : [];

                foreach ($automations as $a => $automation) {
                    $field = "workflows.{$w}.stages.{$s}.automations.{$a}";
                    $key = $automation['messageTemplate'] ?? null;

                    if (! is_string($key)) {
                        continue;
                    }

                    if ($forPack) {
                        $validator->errors()->add($field.'.messageTemplate', __(
                            'A pack is shared by every team and a message template belongs to one, so a shipped '
                            .'automation cannot name one. Keep the automation and drop the template, or import '
                            .'this file into a team instead.',
                        ));

                        continue;
                    }

                    $action = AutomationActionType::tryFrom((string) ($automation['actionType'] ?? ''));
                    $wanted = $action?->channel();

                    if ($wanted !== null && ($channels[$key] ?? null) !== $wanted) {
                        $validator->errors()->add($field.'.messageTemplate', __(
                            'This automation sends on :wanted and its template is not on that channel.',
                            ['wanted' => $wanted->value],
                        ));
                    }
                }
            }
        }
    }

    /**
     * The `messageTemplates` stanzas as they arrived, shape unasserted.
     *
     * Two callers read them **before** validation — one to build the list of
     * keys an automation may name, one to check the channels agree — so
     * neither may assume anything about them. Hence `list<mixed>`: a guard
     * the analyser believes is redundant is a guard that stops being written.
     *
     * @param  array<string, mixed>  $document
     * @return list<mixed>
     */
    private function rawMessages(array $document): array
    {
        /** @var mixed $messages */
        $messages = $document['messageTemplates'] ?? null;

        return is_array($messages) ? array_values($messages) : [];
    }

    /**
     * @return array<string, string>
     */
    private function errorMessages(): array
    {
        return [
            'formatVersion.in' => 'This file is in a pack format this version of the product does not read.',
            'pack.slug.regex' => 'A pack slug is lowercase words joined by hyphens.',
        ];
    }
}
