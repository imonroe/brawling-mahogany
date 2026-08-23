<?php

declare(strict_types=1);

namespace App\Http\Controllers\People;

use App\Enums\ContactImportSource;
use App\Enums\ContactImportState;
use App\Http\Controllers\Controller;
use App\Jobs\CommitContactImport;
use App\Jobs\ParseContactImport;
use App\Models\ContactImport;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact import (PRD §4.2 F2.8 · Screen Inventory S33).
 *
 * F2.8: *"Nobody retypes a client list."* It also sits at step 5 of onboarding
 * (PRD §5.1), between inviting Heather and installing template packs — a team
 * that cannot import is a team that never finishes onboarding.
 *
 * The controller does three small things and hands the work to the queue: take
 * the file, show what the parse found, and commit what was reviewed. Nothing
 * is written to the directory until `commit`.
 */
class ContactImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', ContactImport::class);

        return Inertia::render('People/Import', [
            'sources' => ContactImportSource::options(),
            'recent' => ContactImport::query()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (ContactImport $import): array => $this->summary($import))
                ->all(),
        ]);
    }

    public function store(Request $request, TeamContext $teams): RedirectResponse
    {
        $this->authorize('create', ContactImport::class);

        $validated = $request->validate([
            'source' => ['required', Rule::enum(ContactImportSource::class)],
            'file' => [
                'required',
                'file',
                'max:5120',
                // Extensions rather than MIME types: browsers disagree about
                // what a .vcf is, and every one of them is text.
                'mimes:csv,txt,vcf,json',
            ],
        ]);

        $source = ContactImportSource::from($validated['source']);

        $import = ContactImport::query()->create([
            'requested_by_person_id' => $request->user()->getKey(),
            'source' => $source,
            'state' => ContactImportState::Pending,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            // Keyed by team, not by uploader. The retention purge sweeps a
            // team's prefixes when the team goes (issue #57: *"no rows and no
            // files"*), and a person-keyed prefix left an abandoned CSV — a
            // copy of somebody's whole client list — with nothing pointing at
            // it and nothing sweeping it.
            'disk_path' => $request->file('file')->store('imports/'.$teams->requireId('contact import')),
        ]);

        dispatch((new ParseContactImport($import->getKey()))->forTeam($import->team_id));

        return to_route('people.import.show', $import);
    }

    public function show(ContactImport $import): Response
    {
        $this->authorize('view', $import);

        return Inertia::render('People/Import', [
            'sources' => ContactImportSource::options(),
            'import' => [
                ...$this->summary($import),
                'columnMapping' => $import->column_mapping ?? [],
                'preview' => $import->preview ?? [],
                'failures' => $import->failures ?? [],
            ],
            'recent' => [],
        ]);
    }

    /**
     * Write the reviewed rows.
     *
     * The person may change any row's action first — S33's requirement that
     * they *"let the user change it before anything is written"* — so the
     * submitted actions replace the parser's guesses.
     */
    public function commit(Request $request, ContactImport $import): RedirectResponse
    {
        $this->authorize('update', $import);

        abort_unless($import->state === ContactImportState::AwaitingReview, 409);

        $validated = $request->validate([
            'actions' => ['array'],
            'actions.*' => [Rule::in(['create', 'merge', 'skip'])],
        ]);

        $actions = $validated['actions'] ?? [];

        $import->forceFill([
            'preview' => array_map(
                fn (array $row): array => [
                    ...$row,
                    'action' => $actions[(string) $row['row']] ?? $row['action'] ?? 'create',
                ],
                $import->preview ?? [],
            ),
        ])->save();

        dispatch((new CommitContactImport($import->getKey()))->forTeam($import->team_id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importing your contacts.')]);

        return to_route('people.import.show', $import);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ContactImport $import): array
    {
        return [
            'id' => $import->getKey(),
            'source' => $import->source->value,
            'sourceLabel' => $import->source->label(),
            'state' => $import->state->value,
            'stateLabel' => $import->state->label(),
            'filename' => $import->original_filename,
            'summary' => $import->summary,
            'failureCount' => count($import->failures ?? []),
            'createdAt' => $import->created_at?->toIso8601String(),
            'completedAt' => $import->completed_at?->toIso8601String(),
        ];
    }
}
