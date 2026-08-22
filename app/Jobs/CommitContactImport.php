<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActivitySource;
use App\Enums\ContactImportState;
use App\Enums\PersonLifecycleState;
use App\Jobs\Concerns\RunsForTeam;
use App\Models\ContactImport;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Import\ImportRowRefused;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Write the reviewed import (Screen Inventory S33).
 *
 * Two properties worth stating, because both are requirements rather than
 * niceties:
 *
 *  - **Partial failure is survivable.** A bad row fails on its own and the
 *    rest still land, with the failures reported by row number.
 *  - **Re-running creates nothing new.** The merge path attaches to the
 *    existing membership, so importing the same file twice is a no-op rather
 *    than a doubled directory.
 */
class CommitContactImport implements ShouldQueue
{
    use Queueable, RunsForTeam;

    public function __construct(public readonly string $importId) {}

    public function handle(RecordActivity $activity, AuditLogger $audit): void
    {
        $this->withinTeam(function (Team $team) use ($activity, $audit): void {
            $import = ContactImport::query()->findOrFail($this->importId);

            $import->forceFill(['state' => ContactImportState::Importing])->save();

            $uploadedFile = $import->disk_path;
            $summary = ['created' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 0];
            $failures = $import->failures ?? [];
            $summary['failed'] = count($failures);

            foreach ($import->preview ?? [] as $row) {
                $action = (string) ($row['action'] ?? 'create');

                if ($action === 'skip') {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $outcome = DB::transaction(fn (): string => $this->import($row, $action, $activity));

                    $summary[$outcome]++;
                } catch (ImportRowRefused $refused) {
                    // The reviewed choice cannot be carried out, and doing
                    // the other thing instead would make the review screen a
                    // lie. Say which row and why, in words.
                    $failures[] = ['row' => (int) ($row['row'] ?? 0), 'reason' => $refused->getMessage()];
                    $summary['failed']++;
                } catch (Throwable $exception) {
                    // One row's problem is one row's problem. Row number and
                    // class only — never the value that broke it.
                    $failures[] = ['row' => (int) ($row['row'] ?? 0), 'reason' => $exception::class];
                    $summary['failed']++;
                }
            }

            $import->forceFill([
                'state' => ContactImportState::Completed,
                'summary' => $summary,
                'failures' => $failures,
                'completed_at' => now(),
                // The uploaded file has done its job and is a copy of somebody's
                // whole client list sitting in object storage.
                'disk_path' => null,
            ])->save();

            if ($uploadedFile !== null) {
                Storage::delete($uploadedFile);
            }

            $audit->record(
                action: 'people.imported',
                auditable: $import,
                teamId: $team->getKey(),
                actorPersonId: $import->requested_by_person_id,
                after: $summary,
            );
        });
    }

    /**
     * Carry out **the choice the person reviewed**, not a fresh guess at it.
     *
     * S33's whole promise is that somebody sees what will merge and what will
     * be created, and can change it, before anything is written. Re-deriving
     * the decision here would quietly overrule them — a row they marked
     * "create" would merge, and a row they marked "merge" would create — and
     * the screen would be decoration.
     *
     * Where the choice cannot be carried out, the row is refused with a
     * sentence rather than silently turned into the other one.
     *
     * @param  array<string, mixed>  $row
     * @return 'created'|'merged' what happened to it
     *
     * @throws ImportRowRefused
     */
    private function import(array $row, string $action, RecordActivity $activity): string
    {
        $email = isset($row['email']) && is_string($row['email']) && $row['email'] !== ''
            ? mb_strtolower($row['email'])
            : null;

        $person = $email === null
            ? null
            : Person::query()->whereRaw('lower(email) = ?', [$email])->first();

        /*
         * The choice is about **this team's directory**, which is the
         * question the review screen asked: *do you already have them?* It is
         * not about the shared `people` table — somebody another team knows is
         * still new to you, and importing them attaches a second membership to
         * the one shared row (PRD decision log, 2026-08-22).
         */
        $membership = $person instanceof Person
            ? TeamMembership::query()->where('person_id', $person->getKey())->first()
            : null;

        if ($action === 'merge') {
            if (! $membership instanceof TeamMembership) {
                throw new ImportRowRefused(
                    'Marked as somebody you already have, but nobody in your directory has this address. '.
                    'Mark it “Add as new” instead.',
                );
            }

            return $this->merge($person, $row);
        }

        if ($membership instanceof TeamMembership) {
            throw new ImportRowRefused(
                'Marked to add as new, but this address is already in your directory. '.
                'Mark it “Already have them” instead.',
            );
        }

        $person ??= Person::query()->create([
            'first_name' => (string) $row['first_name'],
            'last_name' => $row['last_name'] ?? null,
            'email' => $email,
            'phone' => $row['phone'] ?? null,
        ]);

        $this->attach($person, $activity);

        return 'created';
    }

    /**
     * Merging is not "do nothing".
     *
     * The imported row usually knows something the record does not — a mobile
     * number, a surname — and the point of an import is to end up knowing more
     * than you started with. Only blanks are filled: another team may know
     * this person too, and their name is not ours to overwrite from somebody
     * else's spreadsheet.
     *
     * @param  array<string, mixed>  $row
     * @return 'merged'
     */
    private function merge(Person $person, array $row): string
    {
        $person->fill(array_filter([
            'last_name' => $person->last_name === null ? ($row['last_name'] ?? null) : null,
            'phone' => $person->phone === null ? ($row['phone'] ?? null) : null,
        ]))->save();

        return 'merged';
    }

    private function attach(Person $person, RecordActivity $activity): void
    {
        TeamMembership::query()->create([
            'person_id' => $person->getKey(),
            // Issue #49: "Imported people default to `lead` status unless the
            // user says otherwise."
            'status' => PersonLifecycleState::Lead,
            'joined_at' => now(),
        ]);

        $activity->record(
            subject: $person,
            eventType: 'person.imported',
            summary: 'Imported into the team directory',
            source: ActivitySource::Import,
        );
    }
}
