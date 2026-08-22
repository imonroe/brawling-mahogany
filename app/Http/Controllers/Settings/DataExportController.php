<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\DataExportState;
use App\Http\Controllers\Controller;
use App\Jobs\ExportTeamData;
use App\Models\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Team data export (Screen Inventory S79, PRD §9, §10).
 *
 * PRD §10: CCPA/CPRA and similar create access obligations that export and
 * deletion cover between them. Built now over three tables it is a morning's
 * work; retrofitted over forty tables it is not.
 *
 * The download is a **signed, expiring** route, never a public link, and the
 * policy refuses an expired export even to the person who asked for it.
 */
class DataExportController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', DataExport::class);

        return Inertia::render('Settings/Export', [
            'exports' => DataExport::query()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (DataExport $export): array => [
                    'id' => $export->getKey(),
                    'state' => $this->displayState($export)->value,
                    'stateLabel' => $this->displayState($export)->label(),
                    'requestedAt' => $export->created_at?->toIso8601String(),
                    'sizeBytes' => $export->size_bytes,
                    'expiresAt' => $export->expires_at?->toIso8601String(),
                    'downloadUrl' => $export->isDownloadable()
                        ? URL::temporarySignedRoute('export.download', $export->expires_at, ['export' => $export->getKey()])
                        : null,
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DataExport::class);

        $export = DataExport::query()->create([
            'requested_by_person_id' => $request->user()->getKey(),
            'state' => DataExportState::Pending,
        ]);

        dispatch((new ExportTeamData($export->getKey()))->forTeam($export->team_id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preparing your export. We’ll have it shortly.')]);

        return to_route('export.index');
    }

    public function download(DataExport $export): StreamedResponse
    {
        $this->authorize('download', $export);

        return Storage::download(
            (string) $export->disk_path,
            'brawling-mahogany-export.json',
        );
    }

    /**
     * "Ready" stops being true the moment the window closes, and a screen that
     * still says Ready next to a link that 403s is worse than one that says
     * Expired.
     */
    private function displayState(DataExport $export): DataExportState
    {
        if ($export->state === DataExportState::Ready && ! $export->isDownloadable()) {
            return DataExportState::Expired;
        }

        return $export->state;
    }
}
