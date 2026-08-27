<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DataExportState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\ContactImport;
use App\Models\DataExport;
use App\Models\Deal;
use App\Models\DealDraft;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentStorage;
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
        $purgedStaging = 0;

        // The scheduler iterates teams explicitly (ADR 0002). There is no
        // ambient team, and a purge would be the single worst place to
        // discover otherwise.
        foreach (Team::query()->withTrashed()->cursor() as $team) {
            /*
             * Files first, then rows — the same order, and for the same
             * reason, as the team purge below.
             *
             * `purgeRowsFor()` hard-deletes any row whose window has closed,
             * `data_exports` and `contact_imports` among them. Running it
             * first deleted the only record of where the file was, and the
             * sweep that came after had nothing left to find: a copy of the
             * team's whole client list still in object storage, and now
             * nothing anywhere pointing at it. Round 2 fixed this shape for
             * an expired export and left it reachable through a purged one.
             */
            $purgedStaging += $teams->runFor($team, fn (): int => $this->purgeExpiredExports()
                + $this->purgeAbandonedImports($cutoff)
                + $this->purgeAbandonedDrafts($cutoff)
                + $this->purgeReadNotifications($team, $cutoff));
            $purgedRows += $this->purgeRowsFor($team, $cutoff);
        }

        // `people` is not team-scoped, so it is purged once rather than per
        // team. See `purgePeople()`.
        $purgedPeople = $this->purgePeople($cutoff, $audit);

        $purgedTeams = $this->purgeTeams($teams, $audit);

        $this->info(
            "Purged {$purgedRows} records, {$purgedPeople} people, ".
            "{$purgedStaging} expired exports, abandoned uploads, drafts and read ".
            "notifications, and {$purgedTeams} teams past the {$days}-day window.",
        );

        return self::SUCCESS;
    }

    private function purgeRowsFor(Team $team, CarbonInterface $cutoff): int
    {
        $purged = 0;

        $this->detachActivityFromExpiringDeals($team, $cutoff);

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
     * A purged deal must not take somebody's contact log with it.
     *
     * `activity_events.deal_id` is a `teamScopedForeign`, so it cascades — and
     * cascade is right for an event *about* the deal: a stage advanced, a
     * workflow attached, a property linked. Those are the deal's own record and
     * they go when it does.
     *
     * It is wrong for an event whose **subject is somebody else**. F2.5 logs a
     * contact against a person and *optionally* a deal, so the deal is context
     * rather than ownership — and letting the cascade reach those meant a
     * client's contact history silently lost entries thirty days after an
     * unrelated deal was purged. The person is still in the directory; the call
     * still happened.
     *
     * The reference is dropped rather than the row, which is also what
     * `deal_id` being nullable is for. It cannot be `nullOnDelete` at the
     * database: the key is composite over `(team_id, deal_id)`, and Postgres
     * would null `team_id` with it — a column that is `NOT NULL` precisely so
     * ADR 0002's scope can never be evaded.
     *
     * The general rule this is the first instance of — *a `teamScopedForeign`
     * that expresses context rather than ownership still cascades, and the
     * purge has to step around it* — is written down in ADR 0002 rather than
     * only here, because the next column of this shape will be added by
     * somebody who never reads this method.
     */
    private function detachActivityFromExpiringDeals(Team $team, CarbonInterface $cutoff): void
    {
        $expiring = DB::table('deals')
            ->where('team_id', $team->getKey())
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->pluck('id');

        if ($expiring->isEmpty()) {
            return;
        }

        DB::table('activity_events')
            ->where('team_id', $team->getKey())
            ->whereIn('deal_id', $expiring)
            /*
             * **Named subjects only, and it fails closed.**
             *
             * The first version kept everything whose subject was *not* the
             * deal, which is the opposite of what the paragraph above says: a
             * stage advanced and a workflow attached are the deal's own record
             * and go with it. Keeping them left orphans — a `stage.advanced`
             * event pointing at a `workflows` row that no longer exists, which
             * `ActivityFeed::subject()` has no branch for and renders forever
             * with neither a subject nor a deal.
             *
             * Worse, it leaked. `ActivityFeed::query()` hides deal-context rows
             * from a viewer without `deals.view` by asking for
             * `whereNull('deal_id')` — so nulling the column moved those rows
             * *into* their feed. A directory-only viewer saw nothing before the
             * purge and a workflow event after it.
             *
             * So the list is what may survive, not what may not: a contact
             * logged against somebody the team knows, which is the case F2.5
             * describes and the only one where the deal is context rather than
             * ownership. Anything else — including a subject type Slice 3 has
             * not added yet — cascades, which is the safe direction.
             */
            /*
             * The allowlist. An exclusion list fails open — a subject type
             * added later would be detached by default — so this names the
             * types that keep their history, and anything new cascades until
             * somebody decides otherwise.
             *
             * `TeamMembership` is here ahead of a caller: everything subjects
             * a `Person` today, but #140 moved every team-visible field onto
             * the membership, so an event about what a team knows about
             * somebody is the membership's to hold. Deciding it now rather
             * than when it appears, because the alternative is a person's
             * contact history vanishing with an unrelated deal — which is the
             * bug this whole method exists for.
             */
            ->whereIn('subject_type', [
                (new TeamMembership)->getMorphClass(),
                (new Person)->getMorphClass(),
            ])
            ->update(['deal_id' => null]);
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
            // Trashed rows included, and this is the half that matters.
            // `HasProductDefaults` brings `SoftDeletes` with it, so the
            // ordinary builder cannot see a deleted export at all — while
            // `purgeRowsFor()` hard-deletes it by table, without model
            // events, moments later. The row went and the archive stayed.
            ->withTrashed()
            ->whereNotNull('disk_path')
            ->where(fn ($query) => $query
                ->where('expires_at', '<', now())
                ->orWhereIn('state', [DataExportState::Expired->value, DataExportState::Failed->value])
                ->orWhereNotNull('deleted_at'))
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
            // Trashed included, for the same reason as the export sweep.
            ->withTrashed()
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
     * Half-finished deals nobody came back to (S14 · issue #74).
     *
     * `purgeRowsFor()` sweeps by `deleted_at`, which is the right rule for
     * everything somebody deleted — and reaches nothing here, because a draft
     * abandoned by *walking away* was never deleted at all. Pressing Discard
     * soft-deletes it and the ordinary pass takes it thirty days later; not
     * pressing anything leaves an open row forever.
     *
     * That is the same shape #61 shipped and round 2 found: a table the purge
     * discovers but never has a reason to act on. The rule that falls out and
     * is worth carrying: **a staging table needs its own sweep, because the
     * thing that ends its life is neglect rather than an action.**
     *
     * Force-deleted rather than soft-deleted, so this does not become a
     * sixty-day window. What is lost is a form somebody stopped filling in a
     * month ago; what they created along the way — a person, a property — is
     * a record in its own right and is untouched.
     */
    private function purgeAbandonedDrafts(CarbonInterface $cutoff): int
    {
        $purged = 0;

        $abandoned = DealDraft::query()
            ->open()
            // `updated_at`, not `created_at`: a draft touched last week is
            // being worked on, however long ago it was started.
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($abandoned as $draft) {
            $draft->forceDelete();

            $purged++;
        }

        return $purged;
    }

    /**
     * Notifications somebody has read and moved on from (#101).
     *
     * `CLAUDE.md`'s rule, arriving with a third table: *"a table that ends by
     * neglect needs its own sweep."* Nothing ever soft-deletes a notification,
     * so `purgeRowsFor()` — which finds rows by `deleted_at` — would never
     * touch this one, and it sits under `ShellCounts`' unread count on every
     * request in the product.
     *
     * **Read ones only.** The column to sweep on is chosen per table, and here
     * it is `read_at`: an unread notification is still doing its job however
     * old it is, and deleting one would answer *"has anybody been told?"* by
     * quietly making it no. A person who has read something and left it thirty
     * days is a person who is finished with it.
     *
     * ## `forceDelete()` on a builder is unscoped, and round 2 of review
     * caught it
     *
     * `Illuminate\Database\Eloquent\Builder::forceDelete()` is one line —
     * `return $this->query->delete()` — and `$this->query` is the **base**
     * builder. It never calls `applyScopes()`, so `TeamScope` is dropped. The
     * same trap `CLAUDE.md` records one method along about `getQuery()` versus
     * `toBase()`, and the SQL says so plainly:
     *
     *     forceDelete(): delete from "notifications" where "read_at" < …
     *     toBase():      delete from "notifications" where "read_at" < …
     *                      and "team_id" = … and "deleted_at" is null
     *
     * (`toBase()` applies `SoftDeletingScope` as well, which is where the
     * `deleted_at` predicate comes from, and the team appears twice because
     * the explicit `where` sits beside `TeamScope`'s. Neither costs anything
     * and both are the point: the statement says what bounds it.)
     *
     * Every team is visited by the loop above, so the *rows* that end up gone
     * are the same set today. That is not a reason to leave it: it made
     * `records:purge` a cross-tenant destructive write held in check by
     * nothing but the shape of its caller, it is invisible to
     * `UnscopedQueryConventionTest` (which reads for `withoutTeamScope` and
     * `withoutGlobalScope`, neither of which appears here), and the first
     * team's pass reported a count belonging to the whole platform.
     *
     * `toBase()` applies the scopes and hands back a base builder, so the
     * `DELETE` is real — `Builder::delete()` would call `SoftDeletes`'
     * `onDelete` and give this table a sixty-day window, which the draft sweep
     * above rejects for the same reason. The team is also named explicitly,
     * because a destructive statement should say what it is bounded by rather
     * than inherit it.
     *
     * The `deleted_at is null` that `SoftDeletingScope` adds leaves nothing
     * unreachable: `notifications` carries `BelongsToTeam`, so a row that ever
     * were soft-deleted is swept by `purgeRowsFor()` on `deleted_at` instead.
     */
    private function purgeReadNotifications(Team $team, CarbonInterface $cutoff): int
    {
        return Notification::query()
            ->where('team_id', $team->getKey())
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->toBase()
            ->delete();
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

                /*
                 * And the uploads (S38, #63), which live on their **own disk**
                 * — that is the whole point of `filesystems.disks.documents`
                 * being private and separate — so the two `Storage::` calls
                 * above never reached them. `purgeableTables()` deletes the
                 * `documents` rows below; without this the bytes outlived the
                 * team that owned them, indefinitely, which is the opposite of
                 * what PRD §9's *"then hard delete"* promises and what F6.4
                 * promises about a private bucket.
                 */
                foreach (Document::query()->withTrashed()->get() as $document) {
                    Storage::disk($document->disk)->delete($document->path);
                }
            });

            // Belt and braces: anything left under the team's own prefixes,
            // including a file whose row was already gone — a document whose
            // parent property was hard-deleted before `HasDocuments` existed
            // has no row left to find it by.
            Storage::deleteDirectory('exports/'.$team->getKey());
            Storage::deleteDirectory('imports/'.$team->getKey());
            Storage::disk(DocumentStorage::DISK)->deleteDirectory((string) $team->getKey());

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
