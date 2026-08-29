<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Audit\AuditLogger;
use App\Support\Templates\PackFile;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;
use JsonException;

/**
 * Write a template pack, or one workflow template, out as a file (#87 · #11).
 *
 * ## The loop this is one half of
 *
 * #87's packs are blocked on #11 — the per-task metadata only a working agent
 * can supply: who owns a task, when it is due, whether it gates an advance,
 * and which stage completions a client should hear about. That is a markup
 * pass over a list that already exists, and the cheapest place to do it is the
 * running product: seed a draft, let somebody edit it on S41, and take back
 * what they produced. This is the taking-back.
 *
 * So the output is meant to be committed. It is pretty-printed, key order is
 * fixed by {@see PackFile}, and rows come out in `sort_order` — a re-export
 * after a one-word change is a one-line diff, which is the difference between
 * a file somebody reviews and a file somebody waves through.
 *
 * ## Standard output by default, and a file when asked
 *
 * `--output` writes; without it the document goes to stdout, which is what
 * makes `packs:export --template=… | jq` work and what keeps this command
 * usable over a shell on a staging box. `GenerateVapidKeys` makes the same
 * choice for a different reason, and its reason does not apply here: a pack
 * file holds a team's process, not a secret.
 */
class ExportTemplatePack extends Command
{
    protected $signature = 'packs:export
                            {--pack= : The slug of a pack in the catalogue}
                            {--template= : The id, or the exact name, of one workflow template}
                            {--output= : Write to this path instead of standard output}';

    protected $description = 'Write a template pack, or one workflow template, out as a pack file.';

    /** Whose data was read, for the audit entry. Null for a pack: it is nobody's. */
    private ?string $exportedTeamId = null;

    /** What was asked for, in the words the operator typed. */
    private string $exportedTarget = '';

    public function handle(TeamContext $teams, AuditLogger $audit): int
    {
        $pack = $this->option('pack');
        $template = $this->option('template');

        if ((is_string($pack) && $pack !== '') === (is_string($template) && $template !== '')) {
            $this->components->error('Name one thing to export: --pack=<slug> or --template=<id|name>.');

            return self::FAILURE;
        }

        $document = is_string($pack) && $pack !== ''
            ? $this->fromPack($teams, $pack)
            : $this->fromTemplate($teams, (string) $template);

        if ($document === null) {
            return self::FAILURE;
        }

        /*
         * Audited on the **read**, before either output branch.
         *
         * `packs:import` was audited and this was not, which is the wrong way
         * round: `fromTemplate()` deliberately reads the whole install rather
         * than one team, and what it emits includes a team's message-template
         * subjects and bodies. Recorded whether it goes to a file or to
         * standard output, because the reading is what happened.
         */
        $audit->record(
            action: 'templates.exported',
            auditable: null,
            teamId: $this->exportedTeamId,
            actorPersonId: null,
            reason: 'Written out as a pack file from the console by a server operator.',
            after: ['target' => $this->exportedTarget],
        );

        try {
            $json = json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $e) {
            $this->components->error('That template could not be written as JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        if (file_put_contents($output, $json) === false) {
            $this->components->error("Could not write to [{$output}].");

            return self::FAILURE;
        }

        $this->components->info("Written to {$output}.");
        $this->components->warn(
            'Read it before committing. A pack ships to every install and is copied on first use, '
            .'so a stage somebody invented teaches a process nobody follows.',
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromPack(TeamContext $teams, string $slug): ?array
    {
        $pack = TemplatePack::query()->where('slug', $slug)->first();

        if (! $pack instanceof TemplatePack) {
            $this->components->error("No pack has the slug [{$slug}].");

            return null;
        }

        /*
         * A pack's rows belong to nobody, so there is no team to run as — and
         * `runFor(null)` is what says that deliberately rather than by
         * omission. `encodePack` reaches `MessageTemplate`, which is
         * `BelongsToTeam`, and a pack's automations can never name one (the
         * CHECK constraint), so the eager load finds no keys and asks the
         * scope nothing. Wrapped anyway, because \"finds no keys\" is a fact
         * about today's data and this is a fact about the caller.
         */
        $this->exportedTarget = 'pack:'.$pack->slug;

        return $teams->runFor(null, fn (): array => PackFile::encodePack($pack));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromTemplate(TeamContext $teams, string $reference): ?array
    {
        /*
         * No team scope to lift: `workflow_templates` is one of the tables
         * ADR 0002 lists as deliberately unscoped, because a null `team_id` is
         * a pack row shared by everybody and a global scope cannot express
         * "mine or everybody's". An operator at a console is asking about the
         * whole install, which is the one caller that wants all of it.
         */
        $matches = WorkflowTemplate::query()
            ->where(fn ($query) => $query->whereKey($reference)->orWhere('name', $reference))
            ->orderBy('name')
            ->get();

        if ($matches->isEmpty()) {
            $this->components->error("No workflow template matches [{$reference}].");

            return null;
        }

        if ($matches->count() > 1) {
            $this->components->error("[{$reference}] names more than one template. Pass an id instead:");

            foreach ($matches as $each) {
                $owner = $each->isSystem() ? 'a pack' : ($each->team->name ?? 'a team');

                $this->line("  {$each->getKey()}  {$each->name}  ({$owner})");
            }

            return null;
        }

        /** @var WorkflowTemplate $template */
        $template = $matches->first();

        /*
         * **Run as the template's own team**, and this is not a nicety.
         *
         * `encodeTemplate` eager-loads `actionDefinitions.messageTemplate`,
         * and `MessageTemplate` is `BelongsToTeam` — so with no team resolved
         * the scope throws `MissingTeamContextException` and the operator gets
         * a stack trace. It broke for exactly the templates this command
         * exists to export: one whose automations send words. A template with
         * none happened to work, because `BelongsTo` skips the query when
         * every foreign key is null.
         *
         * Which is CLAUDE.md's Slice 4 finding again — *\"a test helper that
         * sets up more than the route does hides what the route fails to
         * do\"*. Every test built its fixture inside `runFor`, so the suite
         * never ran this line the way an operator does.
         */
        /*
         * `withTrashed()`, not `$template->team`.
         *
         * A soft-deleted team's rows are still here — `workflow_templates`
         * cascades on a **hard** delete only — so a template inside PRD §9's
         * 30-day window is exportable, and the relation returns null for it.
         * `runFor(null)` then resolves no team and the eager load throws all
         * over again. This is the same defect the round above fixed, one
         * condition along, and CLAUDE.md already names it: *"A soft-deleted
         * tenant must not decide a fact that is not the tenant's.
         * `Team::query()->find()` returns null inside the 30-day purge window
         * … `withTrashed()`."*
         */
        $team = $template->team_id === null
            ? null
            : Team::query()->withTrashed()->find($template->team_id);

        $this->exportedTeamId = $template->team_id;
        $this->exportedTarget = 'template:'.$template->getKey();

        return $teams->runFor($team, fn (): array => PackFile::encodeTemplate($template));
    }
}
