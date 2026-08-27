<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use App\Models\Deal;
use App\Models\Event;
use App\Models\Person;
use App\Models\Team;
use App\Queries\CalendarBoard;
use App\Support\Calendar\IcsDocument;
use App\Support\Calendar\ManageCalendarFeeds;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;

/**
 * S60 — tokenised read-only iCal feeds (PRD §4.8 F8.3 · issue #108).
 *
 * ## Two audiences, and only one of them has a session
 *
 * `store` and `destroy` are the team app: a person managing their own feeds
 * behind `auth` and `team`, like every other screen. There is no `index` —
 * Screen Inventory makes S60 a **modal** over S57, so the list rides in the
 * calendar's own props (`CalendarController`) rather than on a second route
 * that renders a page nobody navigates to.
 *
 * `show` is a **calendar client** — Google's fetcher, or Apple's — with no
 * cookie, no session and no idea what a tenant is. The token establishes the
 * team, which is ADR 0002's stated exception and the same one the client
 * status page makes.
 *
 * ## Read-only, and it is a `GET` that changes nothing a reader can see
 *
 * F8.3 is *"read-only"* in its own title. The only write is the fetch counter,
 * which S60 shows so somebody can tell a live subscription from a forgotten
 * one — and which deliberately is not an audit entry, because a client polls
 * every few hours forever.
 */
class CalendarFeedController extends Controller
{
    public function __construct(private readonly ManageCalendarFeeds $feeds) {}

    public function store(Request $request, TeamContext $teams, CalendarBoard $board): RedirectResponse
    {
        $this->authorize('viewAny', Event::class);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            /*
             * A deal on this team, or nothing. The model's own query, so the
             * team scope applies — a `Rule::exists` builds a raw query the
             * scope never sees, which is the trap `SaveEventRequest` records.
             */
            'dealId' => ['nullable', 'string'],
        ]);

        $person = $request->user();
        $team = $teams->get();

        abort_unless($person instanceof Person && $team instanceof Team, 403);

        $deal = null;

        if (is_string($validated['dealId'] ?? null) && $validated['dealId'] !== '') {
            $deal = Deal::query()->whereKey($validated['dealId'])->first();

            abort_unless($deal instanceof Deal, 404);
        }

        $feed = $this->feeds->generate(
            $team,
            $person,
            $deal,
            trim((string) ($validated['name'] ?? '')) !== ''
                ? trim((string) $validated['name'])
                : $this->defaultName($deal, $team),
        );

        /*
         * Flashed rather than returned as a prop, the way an invitation link
         * is: a credential that lives in a prop is a credential in every
         * subsequent partial reload of the screen. It is *also* readable
         * again from `index`, because a feed URL has to be — see the model —
         * so this is about the reload, not about secrecy.
         */
        return back()->with('calendarFeed', [
            'id' => $feed->getKey(),
            'name' => $feed->name,
            'url' => $feed->url(),
        ]);
    }

    public function destroy(Request $request, CalendarFeed $feed): RedirectResponse
    {
        $this->authorize('viewAny', Event::class);

        /*
         * Somebody's own feed, or an owner tidying up. Written here rather
         * than as a policy because there is no `CalendarFeed` ability worth
         * the file: the question is *"is this yours"*, and the team scope has
         * already answered *"is this ours"*.
         */
        abort_unless(
            $feed->person_id === $request->user()?->getKey()
                || ($request->user()?->can('update', $feed->team) ?? false),
            403,
        );

        $this->feeds->revoke($feed, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Feed revoked.')]);

        return back();
    }

    /**
     * The `.ics` itself, for a calendar client.
     *
     * ## A revoked or unknown token is a 404, never a 403
     *
     * A calendar client is not a person and cannot read a refusal, and the
     * difference between *"wrong"* and *"revoked"* is exactly the difference
     * an attacker would use to confirm a token had once been real. Both are
     * simply not found.
     */
    public function show(string $token, CalendarBoard $board, TeamContext $teams): HttpResponse
    {
        $feed = $this->feeds->findByToken($token);

        abort_unless($feed instanceof CalendarFeed, 404);

        $team = $feed->team;

        abort_unless($team instanceof Team, 404);

        $body = $teams->runFor($team, function () use ($feed, $board, $team): string {
            $timezone = $team->timezone;

            $from = CarbonImmutable::now($timezone)->subDays(CalendarFeed::DAYS_BACK)->startOfDay();
            $to = CarbonImmutable::now($timezone)->addDays(CalendarFeed::DAYS_AHEAD)->endOfDay();

            return IcsDocument::render(
                $feed,
                $board->between($from, $to, $feed->deal),
                $timezone,
            );
        });

        $this->feeds->recordFetch($feed);

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            /*
             * Named, because Apple Calendar uses the filename as the
             * subscription's name when `X-WR-CALNAME` is absent, and because a
             * person who fetches the URL in a browser gets a file rather than
             * a wall of text.
             */
            'Content-Disposition' => 'inline; filename="calendar.ics"',
            /*
             * Never a shared cache. A feed URL is a bearer token, and a proxy
             * has no way to tell one person's calendar from another's.
             */
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // A calendar URL in a search index is somebody's week, published.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * A person's own live feeds, for S60's modal.
     *
     * Static, and called by `CalendarController`: S60 opens over S57, so the
     * list is part of that screen's payload. Here rather than there because
     * the shape belongs with the thing that writes it — a second mapping in
     * the calendar controller is how the two start disagreeing about what a
     * feed row is.
     *
     * **This person's only.** A feed URL is a bearer token, and a colleague's
     * is not something to render on a screen even to somebody who could revoke
     * it — the owner's control lives on the row they are looking at, not on a
     * list of everybody's URLs.
     *
     * @return list<array<string, mixed>>
     */
    public static function feedsFor(?Person $person): array
    {
        if (! $person instanceof Person) {
            return [];
        }

        return array_values(CalendarFeed::query()
            ->where('person_id', $person->getKey())
            ->live()
            ->with('deal')
            ->orderBy('created_at')
            ->get()
            ->map(fn (CalendarFeed $feed): array => [
                'id' => (string) $feed->getKey(),
                'name' => $feed->name,
                'url' => $feed->url(),
                'dealLabel' => $feed->deal?->displayName(),
                'lastFetchedAt' => $feed->last_fetched_at?->toIso8601String(),
                'fetchCount' => $feed->fetch_count,
            ])
            ->all());
    }

    private function defaultName(?Deal $deal, Team $team): string
    {
        return $deal instanceof Deal
            ? $deal->displayName()
            : $team->name.' calendar';
    }
}
