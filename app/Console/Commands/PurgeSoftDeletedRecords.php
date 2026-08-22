<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        // The scheduler iterates teams explicitly (ADR 0002). There is no
        // ambient team, and a purge would be the single worst place to
        // discover otherwise.
        foreach (Team::query()->withTrashed()->cursor() as $team) {
            $purgedRows += $this->purgeRowsFor($team, $cutoff);
        }

        $purgedTeams = $this->purgeTeams($teams, $audit);

        $this->info("Purged {$purgedRows} records and {$purgedTeams} teams past the {$days}-day window.");

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

            foreach ($this->purgeableTables() as $table) {
                DB::table($table)->where('team_id', $team->getKey())->delete();
            }

            // Documents in object storage do not cascade. `documents` lands in
            // Slice 3 and adds its own step here — the checklist issue #57
            // asks this command to own.
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
