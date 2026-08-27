<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
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

        $id = $request->string('notification')->toString();

        $query = Notification::query()->forPerson($person)->unread();

        if ($id !== '') {
            $query->whereKey($id);
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
