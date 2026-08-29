<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Support\Audit\AuditLogger;
use App\Support\Templates\ImportPack;
use App\Support\Templates\ImportReport;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Read a pack file back into rows (#87 · #11).
 *
 * The other half of `packs:export`, and the door the seeder uses too — one
 * writer, so a pack that ships on deploy and a pack somebody imports by hand
 * cannot land differently.
 *
 * ## Two destinations, and neither is a default
 *
 * `--as-pack` writes the shared catalogue every install gets; `--team=<slug>`
 * writes one team's own templates. Naming one is required rather than
 * defaulted, because the two differ in blast radius by every team on the box
 * and *"I forgot the flag"* must not be the difference. `PromotePlatformAdministrator`
 * and `ManageSuppression` make the same choice for the same reason.
 *
 * ## What a team import is *not*
 *
 * It is not an install. Installing a pack is `Use a copy` on S39, which takes
 * a deep copy of a catalogue row a team may read. This is for a file — one
 * somebody exported from a staging box, or one shipped in the repository that
 * a single team wants ahead of a release.
 */
class ImportTemplatePack extends Command
{
    use ConfirmableTrait;

    protected $signature = 'packs:import
                            {file : Path to a pack file}
                            {--as-pack : Write into the shared catalogue, for every team on this install}
                            {--team= : Slug of one team, to import as templates that team owns}
                            {--force : Skip the confirmation prompt in production}';

    protected $description = 'Read a pack file into the catalogue, or into one team.';

    public function handle(ImportPack $import, AuditLogger $audit): int
    {
        $asPack = (bool) $this->option('as-pack');
        $slug = $this->option('team');
        $intoTeam = is_string($slug) && $slug !== '';

        if ($asPack === $intoTeam) {
            $this->components->error('Name one destination: --as-pack, or --team=<slug>.');

            return self::FAILURE;
        }

        $team = null;

        if ($intoTeam) {
            $team = Team::query()->where('slug', $slug)->first();

            if (! $team instanceof Team) {
                $this->components->error("No team has the slug [{$slug}].");

                return self::FAILURE;
            }
        }

        $document = $this->read((string) $this->argument('file'));

        if ($document === null) {
            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $report = $team instanceof Team
                ? $import->intoTeam($document, $team)
                : $import->asPack($document);
        } catch (ValidationException $e) {
            $this->components->error('That file is not a pack this product can read:');

            foreach ($e->validator->errors()->all() as $message) {
                $this->line('  '.$message);
            }

            return self::FAILURE;
        }

        $audit->record(
            action: $team instanceof Team ? 'templates.imported' : 'template_pack.imported',
            auditable: null,
            teamId: $team?->getKey(),
            actorPersonId: null,
            reason: 'Imported from a pack file by a server operator.',
            after: ['pack' => $report->pack, 'templates' => $report->templates],
        );

        $this->report($report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("There is no readable file at [{$path}].");

            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->components->error("Could not read [{$path}].");

            return null;
        }

        try {
            /** @var mixed $document */
            $document = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error("[{$path}] is not valid JSON: ".$e->getMessage());

            return null;
        }

        if (! is_array($document)) {
            $this->components->error("[{$path}] does not hold a pack.");

            return null;
        }

        /** @var array<string, mixed> */
        return $document;
    }

    private function report(ImportReport $report): void
    {
        $this->components->info($report->summary());

        foreach ($report->templates as $name) {
            $this->line("  {$name}");
        }

        /*
         * Printed rather than swallowed, because every one of these is
         * something the importer decided on its own — an association it could
         * not honour, a workflow the file no longer describes, message
         * templates now sitting in a team unread. *"Narrowing a list in
         * silence is not the same as narrowing it."*
         */
        foreach ($report->notes as $note) {
            $this->components->warn($note);
        }
    }
}
