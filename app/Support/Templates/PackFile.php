<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\ActionDefinition;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Str;

/**
 * A template pack as a file, in both directions (#87 · #11).
 *
 * ## Why this exists at all
 *
 * #87 is the seeded Listing and Buyer packs, and it is blocked on #11 — the
 * per-task metadata `task_templates` needs that only a working agent can
 * supply: who owns a task, when it is due, whether it actually gates an
 * advance, and which stage completions are worth telling a client about.
 *
 * That metadata is a **markup pass over a list that already exists**, and the
 * cheapest place to do it is the running product rather than a GitHub comment.
 * Which needs a loop: seed a draft pack → somebody marks it up on S41 → export
 * what they produced → that file is the pack that ships. This class is the two
 * ends of that loop, and it is one class so the two ends cannot disagree.
 *
 * ## One definition, both directions
 *
 * Every field appears exactly once, in a `*_FIELDS` map read by `encode` and
 * by `decode` alike. A separate reader and writer is the shape `KeyDateGraph`
 * warns about one module over — *"the preview and the save are the same
 * function or they are two answers"* — and a format whose two halves drift is
 * one that loses a column silently, which is the failure this loop cannot
 * survive: the whole point is that what Emily typed is what ships.
 *
 * ## `sort_order` is array position, and is in no map
 *
 * A file carrying both an order and an ordering number is a file where the two
 * can disagree, and somebody hand-editing it has to keep them in step. The
 * array is the order. `encode` reads rows already ordered by `sort_order` (the
 * relations order themselves); `decode` hands back position, and the importer
 * numbers from zero.
 *
 * `template_packs.sort_order` is the exception and stays explicit, because it
 * orders packs **against each other** and there is no array holding them.
 *
 * ## Identifiers do not travel
 *
 * No ULID appears in a pack file. An id is meaningful in the database that
 * generated it and nowhere else, and a file carrying one invites an importer
 * to honour it. The one cross-reference a pack needs — an automation naming
 * the message template it sends — travels as a `key`: a slug of the template's
 * name, stable across installs and editable by a person.
 *
 * @phpstan-type PackDocument array<string, mixed>
 */
final class PackFile
{
    /**
     * The format's own version, written into every file.
     *
     * Checked on the way in rather than assumed. A file from a later format
     * read by an earlier importer is the case worth refusing loudly: the
     * fields it does not recognise are exactly the ones it would drop.
     */
    public const VERSION = 1;

    /** @var array<string, string> */
    private const PACK_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'isInstalledByDefault' => 'is_installed_by_default',
        'priceTier' => 'price_tier',
        'sortOrder' => 'sort_order',
    ];

    /** @var array<string, string> */
    private const WORKFLOW_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'version' => 'version',
        'isActive' => 'is_active',
    ];

    /** @var array<string, string> */
    private const STAGE_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'expectedDurationDays' => 'expected_duration_days',
        'ownerRole' => 'owner_role',
        'isMilestone' => 'is_milestone',
        'clientFacingLabel' => 'client_facing_label',
    ];

    /** @var array<string, string> */
    private const GATE_FIELDS = [
        'gateType' => 'gate_type',
        'label' => 'label',
        'isBlocking' => 'is_blocking',
        'config' => 'config',
    ];

    /** @var array<string, string> */
    private const TASK_FIELDS = [
        'title' => 'title',
        'description' => 'description',
        'ownerRole' => 'owner_role',
        'dueOffsetDays' => 'due_offset_days',
        'isRequired' => 'is_required',
    ];

    /** @var array<string, string> */
    private const MESSAGE_FIELDS = [
        'name' => 'name',
        'channel' => 'channel',
        'subject' => 'subject',
        'bodyHtml' => 'body_html',
        'bodyText' => 'body_text',
        'recipientRule' => 'recipient_rule',
        'fromIdentity' => 'from_identity',
    ];

    /**
     * The automation fields that map straight across.
     *
     * `is_manual` and `requires_approval` are deliberately absent: they are one
     * answer the table refuses to hold as two, so the file carries
     * `executionMode` and {@see self::executionModeColumns()} expands it. A
     * file that could say `isManual` and `requiresApproval` independently could
     * say both, and the CHECK constraint would refuse it on the way in — which
     * is a database error where a format error belongs.
     *
     * @var array<string, string>
     */
    private const AUTOMATION_FIELDS = [
        'trigger' => 'trigger',
        'actionType' => 'action_type',
        'config' => 'config',
        'isActive' => 'is_active',
    ];

    /**
     * A whole pack, as a document ready to be written to disk.
     *
     * @return PackDocument
     */
    public static function encodePack(TemplatePack $pack): array
    {
        $pack->loadMissing([
            'workflowTemplates.stageTemplates.gateTemplates',
            'workflowTemplates.stageTemplates.taskTemplates',
            'workflowTemplates.stageTemplates.actionDefinitions.messageTemplate',
            'workflowTemplates.dealTypes',
        ]);

        return self::document(
            pack: ['slug' => $pack->slug] + self::read($pack, self::PACK_FIELDS),
            templates: array_values($pack->workflowTemplates->all()),
        );
    }

    /**
     * One workflow template, as a one-workflow pack document.
     *
     * There is one format rather than two, and this is why: a team's own
     * template is what somebody just finished authoring on S41, and it becomes
     * pack content by being *put in a pack*. A second, template-shaped format
     * would need its own parser, its own tests and its own drift.
     *
     * The pack stanza is derived from the template's own name so the file is
     * importable as it stands. It is meant to be edited — a slug is a
     * catalogue identity and nobody's first template name is one.
     *
     * @return PackDocument
     */
    public static function encodeTemplate(WorkflowTemplate $template): array
    {
        $template->loadMissing([
            'stageTemplates.gateTemplates',
            'stageTemplates.taskTemplates',
            'stageTemplates.actionDefinitions.messageTemplate',
            'dealTypes',
            'templatePack',
        ]);

        $pack = $template->templatePack;

        return self::document(
            pack: $pack instanceof TemplatePack
                ? ['slug' => $pack->slug] + self::read($pack, self::PACK_FIELDS)
                : [
                    'slug' => Str::slug($template->name),
                    'name' => $template->name,
                    'description' => $template->description,
                    'isInstalledByDefault' => false,
                    'priceTier' => null,
                    'sortOrder' => 0,
                ],
            templates: [$template],
        );
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  list<WorkflowTemplate>  $templates
     * @return PackDocument
     */
    private static function document(array $pack, array $templates): array
    {
        /*
         * Gathered across every workflow in the pack, because one "Inspection
         * scheduled" template legitimately serves automations on several of
         * them (PRD §7.12) — so the message templates are a sibling of
         * `workflows` rather than nested inside one, and the key is what
         * re-joins them on the way back in.
         */
        $messages = [];

        foreach ($templates as $template) {
            foreach ($template->stageTemplates as $stage) {
                foreach ($stage->actionDefinitions as $automation) {
                    $message = $automation->messageTemplate;

                    if ($message instanceof MessageTemplate) {
                        $messages[$message->getKey()] = $message;
                    }
                }
            }
        }

        $keys = self::keysFor($messages);

        return [
            'formatVersion' => self::VERSION,
            'pack' => $pack,
            'messageTemplates' => array_values(array_map(
                fn (MessageTemplate $message): array => ['key' => $keys[$message->getKey()]]
                    + self::encodeMessage($message),
                $messages,
            )),
            'workflows' => array_map(
                fn (WorkflowTemplate $template): array => self::encodeWorkflow($template, $keys),
                $templates,
            ),
        ];
    }

    /**
     * A stable, human-readable key per message template, unique within a file.
     *
     * Slugged from the name, because that is the half a person recognises when
     * they open the file to change which template an automation sends. Two
     * templates whose names slug the same get a numbered suffix rather than
     * silently sharing a key — a collision here would re-point one
     * automation's words at the other's on the way back in.
     *
     * @param  array<string, MessageTemplate>  $messages
     * @return array<string, string>
     */
    private static function keysFor(array $messages): array
    {
        $keys = [];
        $taken = [];

        foreach ($messages as $id => $message) {
            $base = Str::slug($message->name);
            $base = $base === '' ? 'message' : $base;

            $key = $base;
            $suffix = 2;

            while (isset($taken[$key])) {
                $key = $base.'-'.$suffix++;
            }

            $taken[$key] = true;
            $keys[$id] = $key;
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $keys  message template id => file key
     * @return array<string, mixed>
     */
    private static function encodeWorkflow(WorkflowTemplate $template, array $keys): array
    {
        return self::read($template, self::WORKFLOW_FIELDS) + [
            /*
             * By name, not by id, and system deal types are the reason it
             * works: PRD §2.2 fixes the three every install has, and
             * `DealTypeSeeder` treats the name as the identity. A pack naming
             * one a team has not got is a pack that leaves the association
             * off, which the importer reports rather than swallows.
             */
            'dealTypes' => $template->dealTypes
                ->map(fn ($type): array => [
                    'name' => $type->name,
                    'isDefault' => (bool) ($type->pivot->is_default ?? false),
                ])
                ->values()
                ->all(),
            'stages' => $template->stageTemplates
                ->map(fn (StageTemplate $stage): array => self::encodeStage($stage, $keys))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    private static function encodeStage(StageTemplate $stage, array $keys): array
    {
        return self::read($stage, self::STAGE_FIELDS) + [
            'gates' => $stage->gateTemplates
                ->map(fn (GateTemplate $gate): array => self::read($gate, self::GATE_FIELDS))
                ->values()
                ->all(),
            'tasks' => $stage->taskTemplates
                ->map(fn (TaskTemplate $task): array => self::read($task, self::TASK_FIELDS))
                ->values()
                ->all(),
            'automations' => $stage->actionDefinitions
                ->map(fn (ActionDefinition $automation): array => self::encodeAutomation($automation, $keys))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    private static function encodeAutomation(ActionDefinition $automation, array $keys): array
    {
        $message = $automation->message_template_id;

        return [
            'trigger' => $automation->trigger->value,
            'actionType' => $automation->action_type->value,
            'config' => $automation->config,
            'isActive' => $automation->is_active,
            /*
             * `executionMode()` is the model's own answer to *"how is a human
             * put in the loop"*, and asking it here rather than reading the two
             * booleans means the file cannot describe the state the table
             * refuses to hold.
             */
            'executionMode' => $automation->executionMode(),
            'messageTemplate' => $message === null ? null : ($keys[$message] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeMessage(MessageTemplate $message): array
    {
        return [
            'name' => $message->name,
            'channel' => $message->channel->value,
            'subject' => $message->subject,
            'bodyHtml' => $message->body_html,
            'bodyText' => $message->body_text,
            'recipientRule' => $message->recipient_rule,
            'fromIdentity' => $message->from_identity,
        ];
    }

    /**
     * Read a row's columns out under their file names.
     *
     * @param  array<string, string>  $fields  file key => column
     * @return array<string, mixed>
     */
    private static function read(object $row, array $fields): array
    {
        $out = [];

        foreach ($fields as $key => $column) {
            /** @var mixed $value */
            $value = $row->{$column};
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * The columns a row of this kind takes from a file stanza.
     *
     * The same maps, read the other way. Every key the format knows about is
     * written, present in the stanza or not, so a column keeps its database
     * default only where the format has no opinion — and a hand-written file
     * that omits `isRequired` gets `false`, which is the value
     * `task_templates` documents as its default and the one a pack must not
     * guess differently.
     *
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function packColumns(array $stanza): array
    {
        return self::write($stanza, self::PACK_FIELDS, [
            'description' => null,
            'isInstalledByDefault' => false,
            'priceTier' => null,
            'sortOrder' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function workflowColumns(array $stanza): array
    {
        return self::write($stanza, self::WORKFLOW_FIELDS, [
            'description' => null,
            'version' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function stageColumns(array $stanza): array
    {
        return self::write($stanza, self::STAGE_FIELDS, [
            'description' => null,
            'expectedDurationDays' => null,
            'ownerRole' => null,
            'isMilestone' => false,
            'clientFacingLabel' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function gateColumns(array $stanza): array
    {
        return self::write($stanza, self::GATE_FIELDS, [
            'isBlocking' => true,
            'config' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function taskColumns(array $stanza): array
    {
        return self::write($stanza, self::TASK_FIELDS, [
            'description' => null,
            'ownerRole' => null,
            'dueOffsetDays' => null,
            'isRequired' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function messageColumns(array $stanza): array
    {
        return self::write($stanza, self::MESSAGE_FIELDS, [
            'subject' => null,
            'bodyHtml' => null,
            'fromIdentity' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public static function automationColumns(array $stanza): array
    {
        return self::write($stanza, self::AUTOMATION_FIELDS, [
            'config' => null,
            'isActive' => true,
        ]) + self::executionModeColumns($stanza['executionMode'] ?? 'automatic');
    }

    /**
     * One answer back into the two columns that hold it.
     *
     * The inverse of {@see ActionDefinition::executionMode()}, and the reason
     * the file has no `isManual`: `action_definitions` carries a CHECK that
     * refuses both at once, so a format able to state them independently is a
     * format able to state a row the database will reject.
     *
     * @return array{is_manual: bool, requires_approval: bool}
     */
    public static function executionModeColumns(mixed $mode): array
    {
        return match ($mode) {
            'manual' => ['is_manual' => true, 'requires_approval' => false],
            'approval' => ['is_manual' => false, 'requires_approval' => true],
            default => ['is_manual' => false, 'requires_approval' => false],
        };
    }

    /**
     * @param  array<string, mixed>  $stanza
     * @param  array<string, string>  $fields  file key => column
     * @param  array<string, mixed>  $defaults  file key => value when absent
     * @return array<string, mixed>
     */
    private static function write(array $stanza, array $fields, array $defaults = []): array
    {
        $out = [];

        foreach ($fields as $key => $column) {
            $out[$column] = array_key_exists($key, $stanza)
                ? $stanza[$key]
                : ($defaults[$key] ?? null);
        }

        return $out;
    }
}
