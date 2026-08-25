<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\ActivitySource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreNoteRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Support\Activity\RecordActivity;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A note on a deal (PRD §4.4 F4.11 · IA §9 · issue #72).
 *
 * ## Not a fourth table
 *
 * PRD §7.7 collapsed three overlapping audit entities into one timeline, and
 * #72 is blunt about not reintroducing one: a note is an `activity_events` row
 * with `source: manual` and `is_client_visible`. `RecordActivity` owns that
 * table, so this controller writes nothing itself — the same rule that keeps
 * `DealTasks` and `DealRoster` the only writers of theirs.
 *
 * ## Internal by default, and the default is the feature
 *
 * F4.11: *"internal by default, with an explicit client-visible toggle."*
 * `RecordActivity::record()` already defaults `isClientVisible` to false, so
 * the safe answer is the one you get by not thinking about it — and the
 * request has to *say* true for anything else to happen. The toggle is a field
 * on one submission and is never carried between notes: a fresh form is a
 * fresh decision, because the failure mode is publishing something to a client
 * by inertia.
 */
class NoteController extends Controller
{
    public function store(
        StoreNoteRequest $request,
        Deal $deal,
        RecordActivity $activity,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        $visible = $request->isClientVisible();

        $activity->record(
            subject: $deal,
            eventType: 'note.added',
            /*
             * The note *is* the summary.
             *
             * Every other event type writes a sentence about something that
             * happened elsewhere; this one has no elsewhere. Putting the body
             * in `payload` and a generic "Note added" here would give the
             * timeline a row nobody can read without expanding it, and the
             * status page a row with nothing in it at all.
             */
            summary: $request->body(),
            source: ActivitySource::Manual,
            actor: $person,
            isClientVisible: $visible,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            /*
             * The confirmation says which of the two things just happened,
             * because they are different acts with different audiences and the
             * screen has just returned to a list where both look alike.
             */
            'message' => $visible
                ? __('Note added, and your client can read it.')
                : __('Note added. Internal only.'),
        ]);

        return back(fallback: route('deals.show', $deal));
    }
}
