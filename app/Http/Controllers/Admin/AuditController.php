<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-wide audit log (IA §5.5 — one of `/admin`'s four sections).
 *
 * Read-only by construction: there is no write path here, the model refuses
 * updates and deletes, and the table's triggers refuse them again.
 */
class AuditController extends Controller
{
    public function __invoke(Request $request, TeamContext $teams): Response
    {
        $action = trim((string) $request->query('action', ''));

        return $teams->runWithoutScope(function () use ($action): Response {
            $query = AuditEntry::query()->with('actor:id,email');

            if ($action !== '') {
                $query->where('action', $action);
            }

            return Inertia::render('Admin/Audit', [
                'action' => $action,
                'actions' => AuditEntry::query()->distinct()->orderBy('action')->pluck('action')->all(),
                'entries' => $query
                    ->latest('created_at')
                    ->paginate(50)
                    ->withQueryString()
                    ->through(fn (AuditEntry $entry): array => [
                        'id' => $entry->getKey(),
                        'action' => $entry->action,
                        'teamId' => $entry->team_id,
                        // The console spans teams, and an actor may be a
                        // platform administrator who is a member of none of
                        // them. `displayNameWithin()` falls back to the
                        // sign-in address, which is theirs rather than a
                        // client's.
                        'actorName' => $entry->actor?->displayNameWithin($entry->team),
                        'auditableType' => $entry->auditable_type,
                        'auditableId' => $entry->auditable_id,
                        'reason' => $entry->reason,
                        'createdAt' => $entry->created_at->toIso8601String(),
                    ]),
            ]);
        });
    }
}
