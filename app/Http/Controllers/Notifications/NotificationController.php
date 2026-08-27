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
 * ## One of these writes on a `GET`, deliberately
 *
 * `open()` marks read on the way through, which makes it a `GET` that
 * mutates. That is a real trade and worth naming rather than leaving for a
 * reader to notice: the alternative is a link that does not mark read, and
 * *opening the thing* is the strongest signal there is that somebody has seen
 * it — stronger than the dismiss button, which is what people press to make a
 * badge go away.
 *
 * What it is **not** is a CSRF hazard worth guarding. The only write is
 * `read_at` on rows already addressed to the person following the link, and
 * the only other effect is the team switch, which is refused unless
 * `activeTeams()` still contains the team. A forged link can cause somebody to
 * mark their own notification read and land on a deal they are a member of.
 * A prefetcher can do the same, which is the honest cost of the trade.
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
    public function open(Request $request, NotificationFeed $feed, string $notification): RedirectResponse
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
         *
         * **The line, not the row.** A folded line stands for as many rows as
         * it folded, so marking only the one clicked leaves the badge saying
         * three after somebody has dealt with all three — the panel telling
         * them their action did not work. The panel's own click handler posts
         * the group already; this is the same rule for a link followed cold,
         * and it asks `NotificationFeed` for the ids rather than rebuilding
         * the grouping key a second time.
         */
        Notification::query()
            ->forPerson($person)
            ->whereIn('id', $feed->idsGroupedWith($person, $row))
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
         * **Validated rather than coerced**, which round 2 of review is why on
         * two counts. `array_filter()` on a scalar is a `TypeError`, so
         * `notifications=x` in the body was a 500 rather than a 422. And the
         * absent-key branch means *"all of mine"* — the Mark all read button —
         * so a list that filtered down to nothing silently became **mark
         * everything read**, which is the one thing on this route somebody
         * cannot undo. A key that is present must be a usable list.
         */
        $validated = $request->validate([
            'notifications' => ['sometimes', 'array', 'min:1'],
            'notifications.*' => ['string', 'ulid'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['notifications'] ?? []);

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
