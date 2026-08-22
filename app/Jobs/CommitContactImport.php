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
                if (($row['action'] ?? 'create') === 'skip') {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $created = DB::transaction(fn (): bool => $this->import($row, $activity));

                    $created ? $summary['created']++ : $summary['merged']++;
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
     * @param  array<string, mixed>  $row
     * @return bool true when a membership was created rather than merged
     */
    private function import(array $row, RecordActivity $activity): bool
    {
        $email = isset($row['email']) && is_string($row['email']) && $row['email'] !== ''
            ? $row['email']
            : null;

        $person = $email === null
            ? null
            : Person::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if (! $person instanceof Person) {
            $person = Person::query()->create([
                'first_name' => (string) $row['first_name'],
                'last_name' => $row['last_name'] ?? null,
                'email' => $email,
                'phone' => $row['phone'] ?? null,
            ]);
        }

        $membership = TeamMembership::query()->where('person_id', $person->getKey())->first();

        if ($membership instanceof TeamMembership) {
            return false;
        }

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

        return true;
    }
}
