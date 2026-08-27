<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Event;
use App\Models\TeamMembership;
use App\Queries\CalendarBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * S57 — the calendar (PRD §4.8 F8.1 · issue #105).
 *
 * ## The library decision, made here and recorded in the Design System
 *
 * §15.3 left it open: *"evaluate building the month grid by hand against
 * adopting one, since most calendar libraries bring heavy styling opinions
 * that will fight this system."* **Built by hand**, and the deciding argument
 * is not the styling — it is the requirement.
 *
 * Screen Inventory calls S57 hard because *"events and deadlines are different
 * things sharing a grid"*, and no calendar library models that. Every one of
 * them has a single event type with a start and an end; a deadline is a moment
 * with legal consequences that nobody attends, and it has to be visually
 * distinct **and sorted above** the 4pm showing on the same square. Adopting a
 * library would mean fighting its cell renderer to express the one thing this
 * screen exists to express, on top of fighting its CSS.
 *
 * A month grid is six rows of seven cells over a range this controller
 * already computes. That is a smaller thing to own than an adapter.
 *
 * ## The window is the team's month, not thirty days
 *
 * PRD §9's display-in-the-team's-zone applied to a *decision* rather than to a
 * rendering, the way `NotifyAboutDeadlines` applies it. A month grid starts on
 * the Sunday before the first and ends on the Saturday after the last, so the
 * range the query gets is the range the grid draws — a screen that fetched a
 * calendar month and then drew six weeks would have empty leading and trailing
 * cells that silently hid events.
 */
class CalendarController extends Controller
{
    /** The three shapes S57 has. */
    private const VIEWS = ['month', 'week', 'agenda'];

    /** How far ahead the agenda looks: a fortnight, matching S59 and F9.1. */
    private const AGENDA_DAYS = 14;

    public function index(Request $request, CalendarBoard $board): Response
    {
        $this->authorize('viewAny', Event::class);

        $timezone = $board->timezone();

        $view = in_array($request->query('view'), self::VIEWS, true)
            ? (string) $request->query('view')
            : 'month';

        $focus = $this->focus($request, $timezone);

        [$from, $to] = $this->window($view, $focus);

        return Inertia::render('Calendar/Index', [
            'view' => $view,
            'focus' => $focus->toDateString(),
            'timezone' => $timezone,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'items' => $board->between($from, $to, null),
            /*
             * The **series**, for the modal, beside the **occurrences** for
             * the grid. Two shapes because they answer different questions: an
             * item is one square on one day, and a weekly series produces four
             * of them, while the form edits the rule that produced them all.
             */
            'editableEvents' => $this->editableEvents($from, $to, $timezone),
            'eventTypes' => EventType::options(),
            /*
             * The two pickers S58 needs, loaded with the page rather than
             * fetched when the modal opens. A team has a handful of open deals
             * and a couple of dozen people; two small queries on a screen
             * somebody opens once a morning is cheaper than a spinner inside a
             * dialog they open ten times.
             */
            'dealOptions' => $this->dealOptions(),
            'attendeeOptions' => $this->attendeeOptions(),
        ]);
    }

    /**
     * The day the view is centred on.
     *
     * Parsed in the team's zone so *"today"* is the team's today. A malformed
     * or absent parameter falls back to now rather than failing: this is a
     * `GET` somebody can arrive at from a bookmark, and a 422 on a calendar
     * URL with a typo in it is worse than showing them this month.
     */
    private function focus(Request $request, string $timezone): CarbonImmutable
    {
        $raw = $request->query('date');

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            try {
                return CarbonImmutable::parse($raw, $timezone)->startOfDay();
            } catch (Throwable) {
                // A well-shaped string that is not a real day — 2026-02-31.
            }
        }

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $view, CarbonImmutable $focus): array
    {
        return match ($view) {
            'week' => [$focus->startOfWeek(CarbonImmutable::SUNDAY), $focus->endOfWeek(CarbonImmutable::SATURDAY)],
            'agenda' => [$focus->startOfDay(), $focus->addDays(self::AGENDA_DAYS)->endOfDay()],
            default => [
                $focus->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY),
                $focus->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY),
            ],
        };
    }

    /**
     * Every event touching the window, in the shape S58 edits.
     *
     * The same `touching()` scope the board uses, so the modal can open on
     * anything the grid drew — including a series whose first occurrence was
     * months ago, which is precisely the row a `whereBetween` on `starts_at`
     * would miss.
     *
     * @return list<array<string, mixed>>
     */
    private function editableEvents(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        return array_values(Event::query()
            ->touching($from, $to)
            ->get()
            ->map(fn (Event $event): array => [
                'id' => (string) $event->getKey(),
                'type' => $event->type->value,
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                /*
                 * In the team's zone, because that is what the form's
                 * `datetime-local` inputs mean — see `SaveEventRequest`.
                 */
                'startsAt' => $event->startsIn($timezone)->toIso8601String(),
                'endsAt' => $event->ends_at === null
                    ? null
                    : $event->endsIn($timezone)->toIso8601String(),
                'isAllDay' => $event->is_all_day,
                'dealId' => $event->deal_id,
                'propertyId' => $event->property_id,
                'attendees' => $event->attendeeIds(),
                'recurrence' => $event->repeats()?->toArray(),
            ])
            ->all());
    }

    /**
     * The deals an event can be hung on.
     *
     * **Open ones only.** A closed deal's calendar is history, and offering
     * three years of them in a picker is how somebody files this Thursday's
     * inspection against a sale that completed in March. A deal reopened later
     * comes back to the list because the scope asks the state rather than a
     * date.
     *
     * @return list<array{id: string, label: string}>
     */
    private function dealOptions(): array
    {
        return array_values(Deal::query()
            ->open()
            ->with('propertyLinks.property', 'participants.membership')
            ->get()
            ->map(fn (Deal $deal): array => [
                'id' => (string) $deal->getKey(),
                'label' => $deal->displayName(),
            ])
            ->sortBy('label')
            ->all());
    }

    /**
     * Who can be put on an event.
     *
     * Every membership the team holds, not only its colleagues: an inspection
     * has an inspector on it and a showing has a client, and both are
     * `team_memberships` rows because that is where Slice 1 put contact
     * details. Filtering to people with logins would make the attendee list
     * unable to name the people who actually attend.
     *
     * @return list<array{id: string, name: string}>
     */
    private function attendeeOptions(): array
    {
        return array_values(TeamMembership::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (TeamMembership $membership): array => [
                'id' => (string) $membership->person_id,
                'name' => $membership->fullName(),
            ])
            ->all());
    }
}
