<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Templates\PackFile;
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

    public function handle(): int
    {
        $pack = $this->option('pack');
        $template = $this->option('template');

        if ((is_string($pack) && $pack !== '') === (is_string($template) && $template !== '')) {
            $this->components->error('Name one thing to export: --pack=<slug> or --template=<id|name>.');

            return self::FAILURE;
        }

        $document = is_string($pack) && $pack !== ''
            ? $this->fromPack($pack)
            : $this->fromTemplate((string) $template);

        if ($document === null) {
            return self::FAILURE;
        }

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
    private function fromPack(string $slug): ?array
    {
        $pack = TemplatePack::query()->where('slug', $slug)->first();

        if (! $pack instanceof TemplatePack) {
            $this->components->error("No pack has the slug [{$slug}].");

            return null;
        }

        return PackFile::encodePack($pack);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromTemplate(string $reference): ?array
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

        return PackFile::encodeTemplate($template);
    }
}
