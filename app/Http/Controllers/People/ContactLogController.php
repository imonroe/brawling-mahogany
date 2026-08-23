<?php

declare(strict_types=1);

namespace App\Http\Controllers\People;

use App\Enums\ActivitySource;
use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The contact log (PRD §4.2 F2.5 · IA §2).
 *
 * IA §2 labels this **Contact Log** in the UI while the code name stays
 * `activity_events` — PRD §7.7 collapsed three overlapping audit entities into
 * one timeline, and a logged phone call is that timeline with
 * `source: manual`.
 *
 * The two-click logging modal (S26) is Slice 2 and has a target Heather can
 * hit from a car. This is the endpoint underneath it.
 */
class ContactLogController extends Controller
{
    public function store(Request $request, TeamMembership $membership, RecordActivity $activity): RedirectResponse
    {
        $this->authorize('create', ActivityEvent::class);
        $this->authorize('view', $membership);

        $validated = $request->validate([
            'contact_type' => ['required', Rule::enum(ContactType::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $type = ContactType::from($validated['contact_type']);

        $activity->record(
            subject: $membership->person,
            eventType: 'contact.logged',
            summary: $type->label(),
            source: ActivitySource::Manual,
            occurredAt: isset($validated['occurred_at']) ? now()->parse($validated['occurred_at']) : null,
            payload: array_filter([
                'contact_type' => $type->value,
                'note' => $validated['note'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            // A logged call is internal. The client status page (Slice 4)
            // reads only events somebody deliberately made visible.
            isClientVisible: false,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact logged.')]);

        return back();
    }
}
