<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\MessageChannel;
use App\Http\Requests\Messages\MessageTemplateRules;
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

            /*
             * Said out loud, because the slug is the only thing matched on and
             * nothing checks that the pack found is the pack the file means.
             * Importing somebody's export whose slug happens to be `listing`
             * renames the shipped Listing pack and rebuilds the stages of
             * every workflow inside it whose name collides — an upsert when it
             * is your own file, and a surprise when it is not.
             */
            if ($pack->exists) {
                $notes[] = "This install already had a pack with the slug “{$pack->slug}”"
                    .($pack->name === $stanza['name'] ? '' : " (“{$pack->name}”)")
                    .', so it was updated rather than added.'
                    .($pack->trashed() ? ' It had been archived, and is back.' : '');
            }

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

            if ($this->rawMessages($document) !== []) {
                // Narrowing a list in silence is not the same as narrowing it.
                // Only a hand-written file reaches this — an export of a pack
                // can never carry one, because a pack's automations cannot
                // name one.
                $notes[] = 'This file carries message templates, and a pack cannot: they belong to a team. '
                    .'They were left out. Import into a team instead if you want the words.';
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
            // `whereNull('team_id')` for the same reason `packTemplate()` has
            // it: this is about the pack's own rows. Safe today only because
            // `CopyTemplate` drops `template_pack_id`, and an asymmetry that
            // rests on another class's behaviour is one that decays.
            ->whereNull('team_id')
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

            // Label => id, for the automations below. A `gate_cleared`
            // automation names its gate by label in a file, because the id it
            // stores belongs to whichever database wrote it — see
            // `PackFile::encodeConfig()`.
            $gateIds = [];

            foreach ($gates as $order => $gate) {
                $written = new GateTemplate;

                $written->forceFill(PackFile::gateColumns($gate) + [
                    'stage_template_id' => $row->getKey(),
                    'sort_order' => $order,
                ])->save();

                $gateIds[(string) $written->label] = (string) $written->getKey();
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
                    'config' => $this->automationConfig($automation, $gateIds),
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
     * An automation's configuration, with the gate label resolved back to an id.
     *
     * The inverse of `PackFile::encodeConfig()`. `gate_cleared` is the one
     * trigger whose configuration names another row, and the row is on the
     * automation's own stage — so the label is looked up among the gates this
     * same stage just wrote. Validation has already refused a label that names
     * none of them, which is why this can be a plain lookup.
     *
     * @param  array<string, mixed>  $automation
     * @param  array<string, string>  $gateIds  gate label => new id
     * @return array<string, mixed>|null
     */
    private function automationConfig(array $automation, array $gateIds): ?array
    {
        $config = $automation['config'] ?? null;

        if (! is_array($config)) {
            return null;
        }

        if (! array_key_exists('gateLabel', $config)) {
            return $config;
        }

        $label = $config['gateLabel'];

        unset($config['gateLabel']);

        $config['gateTemplateId'] = is_string($label) ? ($gateIds[$label] ?? null) : null;

        return $config;
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
                // Live rows only, and the team's own ahead of a system row of
                // the same name: an archived type is one S76 says a team has
                // taken out of circulation, and an unordered `first()` over
                // two rows sharing a name picks whichever the heap offers.
                ->whereNull('archived_at')
                ->orderByRaw('team_id is null')
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
     * ## A name is an identity here, and the database says so
     *
     * `message_templates` carries a unique index over
     * `(team_id, channel, lower(name))` for live rows, so "always create a new
     * one" is not a policy this table allows: importing the same file twice
     * was an unhandled `UniqueConstraintViolationException` at the operator.
     *
     * So a live template of the same name **on the same channel** is reused,
     * and the reuse is reported rather than assumed. That is the honest
     * reading of an index the product already keeps: within a team, that pair
     * *is* the template. What it costs is worth saying plainly, which is what
     * the note does — the words the automation will send are the ones already
     * in the team, which may not be the ones in the file.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $notes
     * @return array<string, string> file key => message template id
     */
    private function writeMessageTemplates(array $document, array &$notes): array
    {
        $written = [];
        $created = 0;
        $reused = [];

        foreach ($this->rawMessages($document) as $index => $stanza) {
            if (! is_array($stanza) || ! is_string($stanza['key'] ?? null)) {
                continue;
            }

            $columns = PackFile::messageColumns($stanza);
            $channel = MessageChannel::tryFrom((string) $columns['channel']);
            $name = (string) $columns['name'];

            $existing = $channel === null ? null : $this->liveTemplateNamed($name, $channel);

            if ($existing instanceof MessageTemplate) {
                /*
                 * Two stanzas in one file whose names fold together are one
                 * template — and the consequence is not a duplicate row but a
                 * **wrong-words send**: the second stanza finds the first
                 * one's row, so the automation the file bound to the second
                 * would send the first one's subject and body to a client.
                 *
                 * Caught here rather than by comparing the names up in
                 * validation, and the difference is the whole finding: a fold
                 * done in PHP is not the fold the unique index does
                 * (`mb_strtolower('ΑΣ')` is `ας`, Postgres `lower()` gives
                 * `ασ`), so a check written that way missed exactly the pairs
                 * the database would collapse — which is the rule
                 * `liveTemplateNamed()` two methods down already states. This
                 * asks the database instead: *is the row I just found one I
                 * wrote a moment ago?*
                 */
                if (in_array((string) $existing->getKey(), $written, strict: true)) {
                    throw ValidationException::withMessages([
                        "messageTemplates.{$index}.name" => __(
                            'Two message templates in this file have the same name on the same channel. '
                            .'A team may hold only one, so one of them would silently become the other.',
                        ),
                    ]);
                }

                $written[$stanza['key']] = (string) $existing->getKey();
                $reused[] = $name;

                continue;
            }

            $template = new MessageTemplate;

            // `fill`, not `forceFill`: `BelongsToTeam` puts `team_id` on from
            // the resolved team, and the trait's own rule is that nothing else
            // ever writes it.
            $template->fill($columns);
            $template->save();

            $written[$stanza['key']] = (string) $template->getKey();
            $created++;
        }

        if ($created > 0) {
            $notes[] = $created.' message '.($created === 1 ? 'template was' : 'templates were')
                .' created for this team. Read them on Templates → Messages before anything sends.';
        }

        if ($reused !== []) {
            $notes[] = 'This team already had '.(count($reused) === 1 ? 'a template' : 'templates')
                .' named '.implode(', ', array_map(fn (string $each): string => '“'.$each.'”', $reused))
                .', so the imported automations point at '.(count($reused) === 1 ? 'it' : 'them')
                .' rather than at new copies. The words that will send are the ones already in the team.';
        }

        return $written;
    }

    /**
     * A live template of this name on this channel, in the current team.
     *
     * Matched in SQL over `lower(name)`, which is what the unique index is
     * over — `DealTypeRules` records the reason at length and it holds here: a
     * fold done in PHP is a different comparison from the index's, so the
     * comparison belongs where the index is.
     *
     * Archived and soft-deleted rows are excluded, matching the index's own
     * predicates: an archived name is free, and reusing an archived template
     * would point an automation at one `ActionDefinition::booted()` refuses.
     */
    private function liveTemplateNamed(string $name, MessageChannel $channel): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->where('channel', $channel->value)
            ->whereNull('archived_at')
            ->first();
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
            $this->checkShapes($validator, $document);
            $this->checkMessageTemplates($validator, $document);
            $this->checkGates($validator, $document);
            $this->checkAutomations($validator, $document, $forPack);
        });

        $validator->validate();

        /*
         * The **original** document, not `validate()`'s return.
         *
         * `validate()` hands back only the keys it had a rule for, and moving
         * the message-template rules out to `checkMessageTemplates()` (where
         * the channel can shape them) therefore emptied every stanza down to
         * its `key` — `recipient_rule` arrived null and `MessageTemplate`
         * threw from a `booted()` hook, two layers from the cause.
         *
         * Writing from the raw document is what stops that recurring: a rule
         * moved or dropped can then only weaken a *refusal*, never silently
         * stop a column being written. Safe because nothing here writes a
         * column it was not asked for — every write goes through a
         * `PackFile::*Columns()` map, which reads the keys the format defines
         * and no others.
         */
        return $document;
    }

    /**
     * @param  list<string>  $messageKeys
     * @return array<string, mixed>
     */
    private function rules(array $messageKeys): array
    {
        $stage = 'workflows.*.stages.*';

        /*
         * ## Where `nullable` is and is not
         *
         * `PackFile::write()` keys on `array_key_exists`, so a key the file
         * **omits** gets the documented default and a key the file sets to
         * `null` writes null. That makes `nullable` a claim about the column:
         * it belongs on `description`, `ownerRole`, `dueOffsetDays` and the
         * other genuinely nullable ones, and it must not appear over a NOT
         * NULL column with a default — `isRequired`, `isBlocking`,
         * `isMilestone`, `isActive`, `sortOrder`, `version` — where an
         * explicit `null` was a 23502 from Postgres in the middle of a deploy.
         *
         * Dropping it gives exactly the right pair, because a non-implicit
         * rule is skipped for an absent key: omit it and the default stands,
         * write `null` and it is refused with a sentence.
         */
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
            'pack.isInstalledByDefault' => ['boolean'],
            'pack.priceTier' => ['nullable', 'string', 'max:255'],
            'pack.sortOrder' => ['integer', 'between:0,65535'],

            /*
             * Only the file's own concerns here — a key that identifies the
             * stanza, and a name. **Everything about the template itself is
             * checked against S46's own rules**, per stanza, in
             * `checkMessageTemplates()`: the channel shapes half of them, and
             * a second list written here would be exactly the drift this
             * codebase keeps finding. What that buys is real — merge fields,
             * a recipient rule the channel can carry, a `from_identity` the
             * mail parser will actually accept, and a body inside the limits.
             */
            'messageTemplates' => ['nullable', 'array'],
            'messageTemplates.*.key' => ['required', 'string', 'max:255', 'distinct'],

            'workflows' => ['required', 'array', 'min:1'],
            /*
             * `distinct`, because `packTemplate()` matches an existing
             * workflow **by name within the pack**: two stanzas sharing a name
             * meant the second one silently overwrote the first and
             * force-deleted its stages, while the report claimed two templates
             * had been written. The name is the identity, so the file may not
             * use one twice.
             */
            'workflows.*.name' => ['required', 'string', 'max:120', 'distinct'],
            'workflows.*.description' => ['nullable', 'string', 'max:2000'],
            // Bounded, like `pack.sortOrder` beside it: the column is an
            // `unsignedInteger`, and an out-of-range value is a driver error
            // in the middle of a deploy rather than a sentence.
            'workflows.*.version' => ['integer', 'between:1,4294967295'],
            'workflows.*.isActive' => ['boolean'],
            'workflows.*.dealTypes' => ['nullable', 'array'],
            'workflows.*.dealTypes.*.name' => ['required', 'string', 'max:255'],
            'workflows.*.dealTypes.*.isDefault' => ['boolean'],

            'workflows.*.stages' => ['nullable', 'array'],
            $stage.'.name' => ['required', 'string', 'max:120'],
            $stage.'.description' => ['nullable', 'string', 'max:2000'],
            $stage.'.expectedDurationDays' => ['nullable', 'integer', 'between:0,365'],
            $stage.'.ownerRole' => ['nullable', 'string', 'max:120'],
            $stage.'.isMilestone' => ['boolean'],
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
            $stage.'.gates.*.isBlocking' => ['boolean'],
            $stage.'.gates.*.config' => ['nullable', 'array'],

            $stage.'.tasks' => ['nullable', 'array'],
            $stage.'.tasks.*.title' => ['required', 'string', 'max:200'],
            $stage.'.tasks.*.description' => ['nullable', 'string', 'max:2000'],
            $stage.'.tasks.*.ownerRole' => ['nullable', 'string', 'max:120'],
            $stage.'.tasks.*.dueOffsetDays' => ['nullable', 'integer', 'between:-365,365'],
            $stage.'.tasks.*.isRequired' => ['boolean'],

            $stage.'.automations' => ['nullable', 'array'],
            /*
             * `selectableOptions()`, the same narrow lists
             * `SaveAutomationRequest` uses — and unlike the gate split above,
             * there is no argument for widening them.
             *
             * A gate type the editor cannot compose is one an evaluator can
             * still answer if a file supplies its configuration. An **action**
             * the build cannot carry out is different: `ExecuteAction`'s
             * `default` arm fails it for every deal on every install and
             * `automations:alert-on-failures` emails the team about each one.
             * `post_closing_offset` is the same shape from the trigger end —
             * a case the enum has and nothing raises, which is CLAUDE.md's own
             * *"the enum case is not the same as the feature"*.
             */
            $stage.'.automations.*.trigger' => ['required', Rule::in(array_keys(AutomationTrigger::selectableOptions()))],
            $stage.'.automations.*.actionType' => ['required', Rule::in(array_keys(AutomationActionType::selectableOptions()))],
            $stage.'.automations.*.executionMode' => ['nullable', Rule::in(['automatic', 'approval', 'manual'])],
            $stage.'.automations.*.isActive' => ['boolean'],
            $stage.'.automations.*.config' => ['nullable', 'array'],
            // Named, not numbered — the file's own key for a message template
            // it also carries. A dangling key is refused rather than nulled:
            // an automation that quietly lost its words is one S44 shows as
            // needing attention over a file that said otherwise.
            $stage.'.automations.*.messageTemplate' => ['nullable', 'string', Rule::in($messageKeys)],
        ];
    }

    /**
     * Every collection in the file is a **list**, not an object.
     *
     * `array` is what JSON hands back for both `[…]` and `{…}`, and
     * `rebuild()` writes `sort_order` straight from the array key — so
     * `"stages": {"first": {…}}` put the string `first` into an
     * `unsignedSmallInteger` and threw a `QueryException` at whoever ran the
     * deploy. Order is array position in this format, and a keyed object has
     * no position to read.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $document
     */
    private function checkShapes(mixed $validator, array $document): void
    {
        $this->walk($document, function (string $path, mixed $value) use ($validator): void {
            if (is_array($value) && ! array_is_list($value)) {
                $validator->errors()->add($path, __(
                    'This has to be a list. Order comes from position in a pack file, and a named object has none.',
                ));
            }
        });
    }

    /**
     * Visit every collection the format defines, by path.
     *
     * One walk rather than five nested loops repeated per check, because the
     * shape of the document is stated once here and the callers say what they
     * want done with it.
     *
     * @param  array<string, mixed>  $document
     * @param  callable(string, mixed): void  $visit
     */
    private function walk(array $document, callable $visit): void
    {
        foreach (['messageTemplates', 'workflows'] as $key) {
            if (array_key_exists($key, $document)) {
                $visit($key, $document[$key]);
            }
        }

        foreach ($this->listAt($document, 'workflows') as $w => $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            foreach (['dealTypes', 'stages'] as $key) {
                if (array_key_exists($key, $workflow)) {
                    $visit("workflows.{$w}.{$key}", $workflow[$key]);
                }
            }

            foreach ($this->listAt($workflow, 'stages') as $st => $stage) {
                if (! is_array($stage)) {
                    continue;
                }

                foreach (['gates', 'tasks', 'automations'] as $key) {
                    if (array_key_exists($key, $stage)) {
                        $visit("workflows.{$w}.stages.{$st}.{$key}", $stage[$key]);
                    }
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $from
     * @return array<int, mixed>
     */
    private function listAt(array $from, string $key): array
    {
        $value = $from[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Hold a message template stanza to the rules S46 holds one to.
     *
     * Per stanza, because half the rules are shaped by the channel — a push
     * template is *prohibited* a subject rather than merely not needing one.
     * The stanza is converted to column names first, so what is validated is
     * literally `MessageTemplateRules::fieldRules()` and not a paraphrase of
     * it.
     *
     * What this closes is not hypothetical. A recipient rule the channel
     * cannot carry saved happily and then threw `MalformedRecipientRule` out
     * of `MessageTemplateController::row()` — which runs for **every**
     * template on S45, so one bad stanza in one pack file made a team's whole
     * Messages screen a 500, with no screen left to fix the row from.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $document
     */
    private function checkMessageTemplates(mixed $validator, array $document): void
    {
        foreach ($this->rawMessages($document) as $index => $stanza) {
            if (! is_array($stanza)) {
                continue;
            }

            $columns = PackFile::messageColumns($stanza);

            $channel = MessageChannel::tryFrom((string) ($columns['channel'] ?? ''));

            $inner = Validator::make(
                $columns,
                MessageTemplateRules::fieldRules($channel) + ['name' => ['required', 'string', 'max:120']],
            );

            foreach ($inner->errors()->toArray() as $field => $errors) {
                foreach ($errors as $message) {
                    // Reported under the file's own key, so the operator is
                    // told which stanza rather than which column of nothing.
                    $validator->errors()->add(
                        "messageTemplates.{$index}.".PackFile::messageFileKey($field),
                        $message,
                    );
                }
            }
        }
    }

    /**
     * The one gate type whose configuration is an identifier.
     *
     * `action_completed` stores an `actionDefinitionId`, and every import
     * rebuilds the automations with fresh ULIDs — `TemplatePackSeeder` runs on
     * every deploy, so the second one would leave the gate pointing at nothing
     * and no screen would say so. `ActionCompletedEvaluator` would report *"has
     * not run yet"* forever: a blocking gate only an **override** can pass,
     * built by a file rather than by two clicks, which is the same state
     * `GateRegistry::selectableOptions()` exists to keep out of the product.
     *
     * Refused rather than rewritten, because there is nothing to rewrite it
     * to: the id names a row in somebody else's database.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $document
     */
    private function checkGates(mixed $validator, array $document): void
    {
        foreach ($this->listAt($document, 'workflows') as $w => $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            foreach ($this->listAt($workflow, 'stages') as $st => $stage) {
                if (! is_array($stage)) {
                    continue;
                }

                foreach ($this->listAt($stage, 'gates') as $g => $gate) {
                    if (! is_array($gate) || ($gate['gateType'] ?? null) !== 'action_completed') {
                        continue;
                    }

                    $validator->errors()->add(
                        "workflows.{$w}.stages.{$st}.gates.{$g}.gateType",
                        __(
                            'An “action completed” gate points at one automation by id, and an id from another '
                            .'install means nothing here — every import rebuilds the automations. Use a different '
                            .'gate type, or add this one on the templates screen after importing.',
                        ),
                    );
                }
            }
        }
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

        foreach ($this->listAt($document, 'workflows') as $w => $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            foreach ($this->listAt($workflow, 'stages') as $s => $stage) {
                if (! is_array($stage)) {
                    continue;
                }

                $gateLabels = array_values(array_map(
                    fn (mixed $gate): string => is_array($gate) && is_string($gate['label'] ?? null)
                        ? $gate['label']
                        : '',
                    $this->listAt($stage, 'gates'),
                ));

                foreach ($this->listAt($stage, 'automations') as $a => $automation) {
                    if (! is_array($automation)) {
                        continue;
                    }

                    $field = "workflows.{$w}.stages.{$s}.automations.{$a}";

                    $this->checkAutomationConfig($validator, $field, $automation, $gateLabels);
                    $key = $automation['messageTemplate'] ?? null;
                    $action = AutomationActionType::tryFrom((string) ($automation['actionType'] ?? ''));

                    /*
                     * Keyed on what the action **needs**, not on whether the
                     * file happened to name a template.
                     *
                     * Keying it on the key let a `send_email` with
                     * `"messageTemplate": null` straight through: the CHECK
                     * constraint is satisfied by a null, so the row was
                     * written — a shared automation that `isComplete()` calls
                     * false, shipped to every install on every deploy, badged
                     * "Needs a template" on S41, skipped in silence by
                     * `RaiseAutomations`, and copied into every team that
                     * installed the pack. A row nothing can reach, seeded.
                     */
                    if ($forPack && $action?->needsMessageTemplate() === true) {
                        $validator->errors()->add($field.'.actionType', __(
                            'A pack is shared by every team and a message template belongs to one, so a shipped '
                            .'automation cannot be one that sends words. Use an action that carries its own title, '
                            .'or import this file into a team instead.',
                        ));

                        continue;
                    }

                    if (! is_string($key)) {
                        continue;
                    }

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
     * The configuration an automation needs to be able to do anything.
     *
     * `config` was `nullable|array` and nothing more, so a file could ship a
     * `create_task` with no title or a `gate_cleared` naming no gate — rows
     * that fail per deal, or fire on nothing, on every install. The same
     * argument `checkAutomations()` already makes about a shared automation
     * that cannot send: *"a row nothing can reach, seeded"*.
     *
     * Mirrors `SaveAutomationRequest`'s config rules, which are written as
     * closures over a request and cannot be shared as they stand. Kept to the
     * keys whose absence is a failure rather than a default.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $automation
     * @param  list<string>  $gateLabels  the labels this stage's own gates carry
     */
    private function checkAutomationConfig(
        mixed $validator,
        string $field,
        array $automation,
        array $gateLabels,
    ): void {
        $config = is_array($automation['config'] ?? null) ? $automation['config'] : [];
        $action = AutomationActionType::tryFrom((string) ($automation['actionType'] ?? ''));
        $trigger = AutomationTrigger::tryFrom((string) ($automation['trigger'] ?? ''));

        $needs = [];

        if ($action === AutomationActionType::CreateTask) {
            $needs['taskTitle'] = __('An automation that creates a task needs a title for it.');
        }

        if ($action === AutomationActionType::ManualPrompt) {
            $needs['instruction'] = __('An automation that prompts somebody needs to say what to do.');
        }

        if ($trigger?->needsKeyDate() === true) {
            $needs['keyDateName'] = __('Name the date this counts from — the same name the deal uses for it.');
        }

        foreach ($needs as $key => $message) {
            if (! is_string($config[$key] ?? null) || trim((string) $config[$key]) === '') {
                $validator->errors()->add($field.'.config.'.$key, $message);
            }
        }

        if ($trigger?->needsGate() !== true) {
            return;
        }

        /*
         * The gate travels as a **label**, not an id — see
         * `PackFile::encodeConfig()`. Two checks, because both failures are
         * silent at runtime: a label naming no gate on this stage produces an
         * automation that fires on nothing, and a label naming two produces
         * one bound to whichever the lookup reached first.
         */
        $label = $config['gateLabel'] ?? null;
        $matches = is_string($label)
            ? count(array_keys($gateLabels, $label, strict: true))
            : 0;

        if ($matches === 1) {
            return;
        }

        $validator->errors()->add($field.'.config.gateLabel', $matches > 1
            ? __('This stage has more than one gate called “:label”, so naming one is ambiguous.', ['label' => $label])
            : __(
                'Name the gate whose clearing starts this, exactly as it is labelled on this stage. '
                .'A gate is named rather than numbered because the ids in a pack file mean nothing here.',
            ));
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
