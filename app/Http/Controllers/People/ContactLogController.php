<?php

declare(strict_types=1);

namespace App\Http\Controllers\People;

use App\Enums\ActivitySource;
use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The contact log (PRD §4.2 F2.5 · Screen Inventory S26 · IA §2).
 *
 * IA §2 labels this **Contact Log** in the UI while the code name stays
 * `activity_events` — PRD §7.7 collapsed three overlapping audit entities into
 * one timeline, and a logged phone call is that timeline with
 * `source: manual`.
 *
 * IA §7: the verb is **Log**, and it means *record something that already
 * happened*. Never "Add note" — a note is written and a contact is logged, and
 * they are different records (#72).
 *
 * ## Two clicks, and what that costs the endpoint
 *
 * S26 is a two-click target because Heather logs a call from a car between
 * showings (PRD F12.3). A form with six required fields does not get filled in
 * from a car, so this validates exactly one required field — the type — and
 * everything else is optional. Which is why `occurred_at` defaults to *now*
 * and the note may be absent: the entry that exists is worth more than the
 * complete one that never got made.
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
            /*
             * Optional, per F2.5: *"against a person and optionally a deal."*
             *
             * Scoped, not a bare `exists`. `Rule::exists` builds its own query
             * on the table and never picks up the model's global scope, so an
             * unscoped rule would accept another team's deal id — the
             * composite foreign key would then refuse it, but as a 500 rather
             * than a 422 naming the field. Same shape as `LinkDealRequest`.
             */
            'deal_id' => [
                'nullable', 'string',
                Rule::exists('deals', 'id')->where(
                    fn ($query) => $query
                        ->where('team_id', app(TeamContext::class)->requireId(Deal::class))
                        ->whereNull('deleted_at'),
                ),
            ],
        ]);

        $type = ContactType::from($validated['contact_type']);

        /*
         * Resolved through the model, so the global scope is the layer that
         * finds it rather than the rule above being the only thing that
         * looked. `sole()` because the id validated as present in this team a
         * line ago: no row here means something changed underneath the
         * request, and that is a failure rather than a silently unattached
         * entry.
         */
        $deal = isset($validated['deal_id'])
            ? Deal::query()->whereKey($validated['deal_id'])->sole()
            : null;

        if ($deal instanceof Deal) {
            $this->authorize('view', $deal);
        }

        $activity->record(
            subject: $membership->person,
            eventType: 'contact.logged',
            summary: $type->label(),
            source: ActivitySource::Manual,
            /*
             * Read in the team's timezone, stored in UTC (PRD §9).
             *
             * The field is a `datetime-local`, and what a browser puts in one
             * is wall-clock time with no zone on it at all. Parsing that as
             * UTC would file a 9am call at 9am UTC — four in the morning for a
             * Denver team — and put it on yesterday's date in the feed.
             */
            occurredAt: isset($validated['occurred_at'])
                ? CarbonImmutable::parse($validated['occurred_at'], $this->teamTimeZone())->utc()
                : null,
            payload: array_filter([
                'contact_type' => $type->value,
                'note' => $validated['note'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            // A logged call is internal. The client status page (Slice 4)
            // reads only events somebody deliberately made visible.
            isClientVisible: false,
            deal: $deal,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact logged.')]);

        return back();
    }

    /**
     * The team's display timezone, which is the zone a typed wall-clock time
     * is in. Falls back to the application default when a team has none set.
     */
    private function teamTimeZone(): string
    {
        $timezone = app(TeamContext::class)->get()?->timezone;

        return $timezone === null || $timezone === ''
            ? config()->string('app.timezone')
            : $timezone;
    }
}
