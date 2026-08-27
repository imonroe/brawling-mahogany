<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\Notification;
use App\Models\Person;
use App\Support\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S08 — the notification panel (PRD §4.12 F12.4 · issue #101).
 *
 * ## No policy, and that is not an omission
 *
 * Every other controller in this product authorizes, and
 * `AuthorizationCoverageTest` holds them to it. These read
 * `Notification::forPerson($me)` — rows addressed to the person asking, by
 * their own id — so the authorization *is* the predicate. A policy would be a
 * second thing to keep in step with the query that already decides.
 *
 * ## It reads across teams, deliberately
 *
 * Issue #101: *"a person in two teams needs to know which one a notification
 * came from, and switching teams should not hide it."* A stager working two
 * agencies who is told at nine that a task is theirs must not lose it by
 * switching to the other team at ten. Each line names its team.
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationFeed $feed): Response
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        return Inertia::render('Notifications/Index', [
            'groups' => $feed->groupsFor($person, NotificationFeed::PAGE),
        ]);
    }

    /**
     * Open what a notification is about, from whichever team you are in.
     *
     * ## Why this exists rather than a plain link to the deal
     *
     * The panel reads across teams on purpose (#101), so a line can be about a
     * deal the **resolved** team cannot see — and Deal's team-scoped route
     * binding turns a direct link into a 404 for exactly the person the
     * cross-team panel is for. Review measured it.
     *
     * So this switches first and then redirects: one click works from either
     * team, which is what *"switching teams should not hide it"* has to mean
     * to be worth anything.
     *
     * The switch is deliberately the same one S09 performs — the session key
     * `ResolveCurrentTeam` reads, and nothing else — so a job dispatched from
     * the next request captures the team the person is now in.
     *
     * Authorised by the predicate, like the rest of this controller: the
     * notification is found among **this person's**, and a stranger's id is a
     * 404 rather than a 403 for the reason `TeamSwitchController` gives —
     * confirming a row exists is itself a disclosure.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        $row = Notification::query()->forPerson($person)->whereKey($notification)->first();

        abort_unless($row instanceof Notification, 404);

        $url = $row->url();

        abort_unless($url !== null, 404);

        /*
         * Read on the way through, because opening it is the strongest signal
         * there is that somebody has seen it — stronger than the dismiss
         * button, which is the thing people press to make a badge go away.
         */
        Notification::query()
            ->forPerson($person)
            ->whereKey($row->getKey())
            ->unread()
            ->update(['read_at' => now(), 'updated_at' => now()]);

        /*
         * Only when they are actually in it. A membership revoked since the
         * notification was written must not be re-established by following a
         * link, which is why this asks `activeTeams()` rather than trusting
         * the row's own `team_id`.
         */
        $target = $person->activeTeams()->firstWhere('id', $row->team_id);

        if ($target !== null) {
            $request->session()->put(ResolveCurrentTeam::SESSION_KEY, $target->getKey());
        }

        return redirect($url);
    }

    /**
     * Mark one read, or all of them.
     *
     * One route rather than two, because *"mark all read"* is the same verb
     * with no subject — and #101 lists it as a state of the panel rather than
     * a separate screen.
     */
    public function read(Request $request): RedirectResponse
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        /*
         * **A list, not one id.** A folded line stands for as many rows as it
         * folded, and the first version fired one request per id — which
         * Inertia's sync stream then aborted one after another (measured at 11
         * of 12 cancelled), while each survivor re-ran the whole feed. One
         * request naming them all is both correct and cheaper.
         *
         * No ids at all still means *"all of mine"*, which is the Mark all
         * read button.
         */
        $ids = array_values(array_filter(
            $request->input('notifications', []),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        $query = Notification::query()->forPerson($person)->unread();

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $query->update(['read_at' => now(), 'updated_at' => now()]);

        /*
         * `back()` rather than a named route: the panel is opened from every
         * screen in the shell, and sending somebody to the notifications page
         * because they dismissed a line is the opposite of what they asked
         * for.
         */
        return back();
    }
}
