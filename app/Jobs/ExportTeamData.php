<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DataExportState;
use App\Jobs\Concerns\RunsForTeam;
use App\Models\ActivityEvent;
use App\Models\DataExport;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Build a team's own copy of its data (PRD §9 · Screen Inventory S79).
 *
 * Queued because *"a team with 500 past clients and 2,000 activity events is
 * not exporting in a web request."*
 *
 * **Documents are metadata and a manifest, not files.** Issue #56 left that as
 * a size and liability question: a tenant archive containing every uploaded
 * inspection report is a second copy of the riskiest data the product holds,
 * sitting behind a link. The manifest names what exists so the team can ask
 * for a file; the archive does not carry them. Documents land in Slice 3 and
 * `manifest.documents` is where they attach.
 */
class ExportTeamData implements ShouldQueue
{
    use Queueable, RunsForTeam;

    public function __construct(public readonly string $exportId) {}

    public function handle(AuditLogger $audit): void
    {
        $this->withinTeam(function (Team $team) use ($audit): void {
            $export = DataExport::query()->findOrFail($this->exportId);

            $export->forceFill(['state' => DataExportState::Preparing])->save();

            try {
                $path = $this->write($team, $export);

                $export->forceFill([
                    'state' => DataExportState::Ready,
                    'disk_path' => $path,
                    'size_bytes' => Storage::size($path),
                    'expires_at' => now()->addHours(DataExport::LIFETIME_HOURS),
                    'completed_at' => now(),
                ])->save();

                $audit->record(
                    action: 'team.exported',
                    auditable: $export,
                    teamId: $team->getKey(),
                    actorPersonId: $export->requested_by_person_id,
                );
            } catch (Throwable $exception) {
                $export->forceFill([
                    'state' => DataExportState::Failed,
                    // The class, not the message: an exception message can
                    // carry a row's contents, and PRD §9 says no PII in logs.
                    'error' => $exception::class,
                ])->save();

                throw $exception;
            }
        });
    }

    private function write(Team $team, DataExport $export): string
    {
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'team' => [
                'name' => $team->name,
                'slug' => $team->slug,
                'timezone' => $team->timezone,
            ],
            'people' => TeamMembership::query()
                ->with('person')
                ->get()
                ->map(fn (TeamMembership $membership): array => [
                    'first_name' => $membership->person->first_name,
                    'last_name' => $membership->person->last_name,
                    'email' => $membership->person->email,
                    'phone' => $membership->person->phone,
                    'status' => $membership->status->value,
                    'is_vendor' => $membership->is_vendor,
                    'notes' => $membership->notes,
                    'vendor' => [
                        'specialties' => $membership->vendor_specialties,
                        'typical_cost' => $membership->vendor_typical_cost,
                        'service_area' => $membership->vendor_service_area,
                        'rating' => $membership->vendor_rating,
                        'notes' => $membership->vendor_notes,
                    ],
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                    'revoked_at' => $membership->revoked_at?->toIso8601String(),
                ])->all(),
            'activity' => ActivityEvent::query()
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (ActivityEvent $event): array => [
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'event_type' => $event->event_type,
                    'source' => $event->source,
                    'summary' => $event->summary,
                    'payload' => $event->payload,
                    'is_client_visible' => $event->is_client_visible,
                ])->all(),
            'manifest' => [
                // Slice 3 fills this. Named now so the shape does not change
                // under an already-published export format.
                'documents' => [],
            ],
        ];

        $path = "exports/{$team->getKey()}/{$export->getKey()}.json";

        Storage::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }
}
