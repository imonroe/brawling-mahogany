<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\SaveEventRequest;
use App\Models\Event;
use App\Models\Person;
use App\Queries\CalendarBoard;
use App\Support\Calendar\SaveEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * S58 — the event modal (PRD §4.8 F8.1 · issue #105).
 *
 * A modal over S57, so every action redirects back to the calendar with the
 * view and focus the person was on. Losing their place on save is the small
 * thing that makes a modal feel like a page.
 */
class EventController extends Controller
{
    public function store(SaveEventRequest $request, SaveEvent $events, CalendarBoard $board): RedirectResponse
    {
        $events->add($request->eventAttributes($board->timezone()), $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event added.')]);

        return $this->backToCalendar($request);
    }

    public function update(
        SaveEventRequest $request,
        Event $event,
        SaveEvent $events,
        CalendarBoard $board,
    ): RedirectResponse {
        $events->edit($event, $request->eventAttributes($board->timezone()), $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event updated.')]);

        return $this->backToCalendar($request);
    }

    public function destroy(Request $request, Event $event, SaveEvent $events): RedirectResponse
    {
        $this->authorize('delete', $event);

        $events->remove($event, $this->actor($request));

        /*
         * IA §7: **Remove** detaches and the record survives; **Delete**
         * destroys. This soft-deletes, and the retention purge (PRD §9) is
         * what eventually destroys it — so the word a person reads is
         * *Removed*, and the thirty-day window is real rather than a claim.
         */
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event removed.')]);

        return $this->backToCalendar($request);
    }

    /**
     * Back where they were: the same view, on the same day.
     *
     * Read off the request rather than remembered server-side, because the
     * calendar's position is a URL — somebody can bookmark next month — and a
     * session copy of it would be a second answer that drifts the moment two
     * tabs are open.
     */
    private function backToCalendar(Request $request): RedirectResponse
    {
        return to_route('calendar.index', array_filter([
            'view' => $request->input('returnView'),
            'date' => $request->input('returnDate'),
        ], is_string(...)));
    }

    private function actor(Request $request): ?Person
    {
        $person = $request->user();

        return $person instanceof Person ? $person : null;
    }
}
