<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Templates\ImportPack;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

/**
 * The packs that ship with the product (#87 · PRD §4.4 F4.2, F4.13).
 *
 * Reference data, in the same sense `DealTypeSeeder` is: a pack is a catalogue
 * entry identical for everybody, and F4.2 says the product ships working
 * templates rather than an empty screen. So this runs everywhere, including
 * production, and it runs on every deploy — `ImportPack::asPack()` upserts on
 * the pack's slug, so a corrected stage ships with the code that corrected it
 * and a second run inserts nothing.
 *
 * ## It finds nothing today, and that is the honest state
 *
 * `database/packs/` is empty because #87 is blocked on #11 — Emily's and
 * Heather's real lists, and the per-task metadata `task_templates` needs. The
 * Build Plan's instruction is unchanged and worth repeating where somebody
 * would otherwise be tempted: **build the mechanism, do not invent the
 * content.** A pack ships to every install and is copied on first use, so
 * stages somebody made up would teach a process nobody follows and would be in
 * flight before anyone noticed. An empty templates screen is honest; a
 * plausible wrong one is not.
 *
 * Dropping a file into `database/packs/` is all that is left, which is the
 * point of wiring this up before the content exists.
 *
 * ## A bad file stops the deploy
 *
 * Thrown rather than warned. A pack that half-imported is a catalogue every
 * team can see and copy, in a state nobody authored — and the seed step runs
 * beside `migrate --force`, where a loud failure is read and a warning
 * scrolls past.
 */
class TemplatePackSeeder extends Seeder
{
    public function run(): void
    {
        $import = app(ImportPack::class);

        foreach ($this->files() as $path) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Could not read the pack file at [{$path}].");
            }

            try {
                /** @var mixed $document */
                $document = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("[{$path}] is not valid JSON: ".$e->getMessage(), previous: $e);
            }

            if (! is_array($document)) {
                throw new RuntimeException("[{$path}] does not hold a pack.");
            }

            /** @var array<string, mixed> $document */
            $report = $import->asPack($document);

            $this->command->getOutput()->writeln("  <info>Pack:</info> {$report->summary()}");

            foreach ($report->notes as $note) {
                $this->command->getOutput()->writeln("  <comment>{$note}</comment>");
            }
        }
    }

    /**
     * Sorted, so a pack that references nothing still imports in a stable
     * order and two installs of the same release hold the same rows.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $found = glob(database_path('packs/*.json'));

        if ($found === false) {
            return [];
        }

        sort($found);

        return $found;
    }
}
