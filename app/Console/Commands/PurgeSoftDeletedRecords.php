<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DataExportState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\ContactImport;
use App\Models\DataExport;
use App\Models\Person;
use App\Models\Team;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * The 30-day purge (PRD §9 Deletion · issue #57).
 *
 * *"Soft delete with a 30-day recovery window, then hard delete. Team deletion
 * purges within 30 days."*
 *
 * Two obligations, seen from two sides: rows whose window has closed, and
 * teams whose window has closed.
 *
 * **The table list is discovered, not maintained.** Issue #57 owns *"the
 * framework and the checklist"*, and a checklist somebody has to remember to
 * extend is a checklist that is wrong by Slice 4 — so every team-scoped table
 * with a `deleted_at` is found by reflection.
 *
 * **The deletes are table-level, not Eloquent.** A hard purge wants no model
 * events: a `deleting` observer that cascades a soft delete to children is
 * correct for a user pressing Delete and exactly wrong here, where the
 * database's own `ON DELETE CASCADE` (ADR 0002, layer 2) already reaches the
 * children and reaches them faster.
 */
class PurgeSoftDeletedRecords extends Command
{
    protected $signature = 'records:purge {--days=30 : The recovery window, in days}';

    protected $description = 'Hard-delete records and teams whose recovery window has closed.';

    public function handle(TeamContext $teams, AuditLogger $audit): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $purgedRows = 0;
        $purgedFiles = 0;

        // The scheduler iterates teams explicitly (ADR 0002). There is no
        // ambient team, and a purge would be the single worst place to
        // discover otherwise.
        foreach (Team::query()->withTrashed()->cursor() as $team) {
            $purgedRows += $this->purgeRowsFor($team, $cutoff);
            $purgedFiles += $teams->runFor($team, fn (): int => $this->purgeExpiredExports()
                + $this->purgeAbandonedImports($cutoff));
        }

        // `people` is not team-scoped, so it is purged once rather than per
        // team. See `purgePeople()`.
        $purgedPeople = $this->purgePeople($cutoff, $audit);

        $purgedTeams = $this->purgeTeams($teams, $audit);

        $this->info(
            "Purged {$purgedRows} records, {$purgedPeople} people, ".
            "{$purgedFiles} expired export files, ".
            "and {$purgedTeams} teams past the {$days}-day window.",
        );

        return self::SUCCESS;
    }

    private function purgeRowsFor(Team $team, CarbonInterface $cutoff): int
    {
        $purged = 0;

        foreach ($this->purgeableTables() as $table) {
            $purged += DB::table($table)
                ->where('team_id', $team->getKey())
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $cutoff)
                ->delete();
        }

        return $purged;
    }

    /**
     * An expired export is a file, not just a row.
     *
     * The archive is a copy of the team's whole record set — every client,
     * every phone number — sitting in object storage behind a link that has
     * stopped working. Marking the row expired without deleting the file
     * leaves the riskiest object the product writes lying about indefinitely,
     * which is PRD §9's deletion policy honoured on paper only.
     */
    private function purgeExpiredExports(): int
    {
        $purged = 0;

        $expired = DataExport::query()
            ->whereNotNull('disk_path')
            ->where(fn ($query) => $query
                ->where('expires_at', '<', now())
                ->orWhereIn('state', [DataExportState::Expired->value, DataExportState::Failed->value]))
            ->get();

        foreach ($expired as $export) {
            Storage::delete((string) $export->disk_path);

            $export->forceFill([
                'state' => DataExportState::Expired,
                'disk_path' => null,
                'size_bytes' => null,
            ])->save();

            $purged++;
        }

        return $purged;
    }

    /**
     * An import somebody started and never finished still holds its upload.
     *
     * `CommitContactImport` deletes the file when the import completes, which
     * covers the ordinary path and not the one that matters: a review screen
     * somebody opened, thought better of, and closed. The CSV is the same
     * object — a copy of a whole client list — and nothing was ever going to
     * come back for it.
     *
     * The row stays. It is the record that an import was attempted, and it
     * carries no contact data once the preview and the file are gone.
     */
    private function purgeAbandonedImports(CarbonInterface $cutoff): int
    {
        $purged = 0;

        $abandoned = ContactImport::query()
            ->whereNotNull('disk_path')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($abandoned as $import) {
            Storage::delete((string) $import->disk_path);

            // The state is left as it was. There is no "expired" import in
            // the vocabulary and inventing one to describe a cleanup would
            // put a word on a screen to serve the purge rather than the
            // reader. What the row says — an import was started, on this
            // date, by this person — is still true; what it no longer holds
            // is anybody's contact details.
            $import->forceFill([
                'disk_path' => null,
                'preview' => null,
            ])->save();

            $purged++;
        }

        return $purged;
    }

    /**
     * Deleted accounts, hard-deleted once the window closes.
     *
     * This one is easy to miss and was: `purgeableTables()` discovers tables
     * by looking for `BelongsToTeam`, and `people` structurally cannot carry
     * it — that is the shared-record decision (#18). So the table holding
     * names, addresses, phone numbers, password hashes, and two-factor
     * secrets was the one table the retention job could never reach, and PRD
     * §9's *"soft delete with a 30-day recovery window, then hard delete"*
     * stopped at the soft delete.
     *
     * It is purged outside the per-team loop, because a person belongs to no
     * team. What survives them is already decided by the schema, and decided
     * the right way round: `activity_events.actor_person_id`,
     * `contact_imports`, `data_exports`, and `team_invitations` all null the
     * reference, so the team's record of what happened stays intact with the
     * human's name gone from it; `team_memberships` and `passkeys` cascade,
     * because a membership without a person and a credential without a holder
     * are nothing at all.
     *
     * That is deletion doing what F1.3 does not: revocation preserves the
     * name, and an erasure past its window removes it. The audit entry is
     * what remains, and it carries the id rather than the address.
     */
    private function purgePeople(CarbonInterface $cutoff, AuditLogger $audit): int
    {
        $due = Person::query()
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->get();

        foreach ($due as $person) {
            $audit->record(
                action: 'person.purged',
                auditableType: Person::class,
                auditableId: $person->getKey(),
                teamId: null,
                actorPersonId: null,
            );
        }

        // Table-level, like every other purge here: no model events on a hard
        // delete, and the database's own cascades reach further and faster.
        return DB::table('people')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->delete();
    }

    /**
     * A team past its cancellation window leaves nothing behind but its audit
     * trail — which is the proof the obligation was met, so it survives
     * (`audit_log` carries no foreign key to `teams` for exactly this reason).
     */
    private function purgeTeams(TeamContext $teams, AuditLogger $audit): int
    {
        $purged = 0;

        $due = Team::query()
            ->withTrashed()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<', now())
            ->get();

        foreach ($due as $team) {
            $audit->record(
                action: 'team.purged',
                auditableType: Team::class,
                auditableId: $team->getKey(),
                teamId: $team->getKey(),
                after: ['slug' => $team->slug],
            );

            /*
             * Files first, while the rows that name them still exist.
             *
             * Object storage does not cascade, and issue #57 is explicit that
             * *"a purged team leaves no rows and no files"*. Deleting the rows
             * first would leave the archives with nothing pointing at them —
             * which is exactly how they survived until now.
             */
            $teams->runFor($team, function (): void {
                foreach (DataExport::query()->whereNotNull('disk_path')->get() as $export) {
                    Storage::delete((string) $export->disk_path);
                }

                foreach (ContactImport::query()->whereNotNull('disk_path')->get() as $import) {
                    Storage::delete((string) $import->disk_path);
                }
            });

            // Belt and braces: anything left under the team's own prefixes,
            // including a file whose row was already gone. `documents` lands
            // in Slice 3 and adds its prefix here — the checklist issue #57
            // asks this command to own.
            Storage::deleteDirectory('exports/'.$team->getKey());
            Storage::deleteDirectory('imports/'.$team->getKey());

            foreach ($this->purgeableTables() as $table) {
                DB::table($table)->where('team_id', $team->getKey())->delete();
            }

            $team->forceDelete();
            $purged++;
        }

        return $purged;
    }

    /**
     * Every team-scoped table that carries a recovery window.
     *
     * @return list<string>
     */
    private function purgeableTables(): array
    {
        static $tables = null;

        if ($tables !== null) {
            return $tables;
        }

        $tables = [];

        foreach ((new Finder)->files()->in([app_path('Models')])->name('*.php') as $file) {
            $class = 'App\\Models\\'.Str::replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array(BelongsToTeam::class, class_uses_recursive($class), true)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (Schema::hasColumn($table, 'deleted_at')) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }
}
