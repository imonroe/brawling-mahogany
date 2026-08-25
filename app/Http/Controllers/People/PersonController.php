<?php

declare(strict_types=1);

namespace App\Http\Controllers\People;

use App\Actions\People\SavePerson;
use App\Actions\Teams\RevokeMembership;
use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StorePersonRequest;
use App\Http\Requests\People\UpdatePersonRequest;
use App\Models\ActivityEvent;
use App\Models\TeamMembership;
use App\Queries\ActivityFeed;
use App\Queries\PeopleDirectory;
use App\Queries\PersonDeals;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people directory (Screen Inventory S30, S31, S32).
 *
 * The route parameter is a `TeamMembership`, not a `Person`. That is the
 * shared-record decision (#18) in one line: a person is shared across teams,
 * and what *this* team may see and edit about them is the membership. Binding
 * to the membership means the global scope does the isolation work — there is
 * no `/people/{person}` route that could reach somebody this team never met.
 */
class PersonController extends Controller
{
    public function index(Request $request, PeopleDirectory $directory): Response
    {
        $this->authorize('viewAny', TeamMembership::class);

        $segment = PersonSegment::tryFrom((string) $request->query('segment', 'all')) ?? PersonSegment::All;
        $search = trim((string) $request->query('search', ''));

        /*
         * S34's two filters (#83). PRD §5.9 step 4 is the whole value of the
         * vendor directory — *"filtering by specialty surfaces him with his
         * rating and history"* — and the query object ignores them on every
         * other segment, so a stale bookmark cannot empty the Clients tab.
         */
        $vendorFilters = [
            'specialty' => trim((string) $request->query('specialty', '')),
            'area' => trim((string) $request->query('area', '')),
        ];

        return Inertia::render('People/Index', [
            'segment' => $segment->value,
            'segmentCounts' => $directory->segmentCounts(),
            'emptyMessage' => $segment->emptyMessage(),
            'search' => $search,
            'vendorFilters' => $vendorFilters,
            /*
             * Every specialty this team has actually typed, for the filter's
             * own options. Read from the rows rather than from a lookup table:
             * IA §13.3 made specialties free text on purpose, so the list of
             * them is whatever the team has written and cannot be seeded.
             *
             * Only on the segment that renders the filter. It is one query,
             * and one query on four segments that never draw the control is
             * the shape of cost `PeopleIndexBudgetTest`'s ceiling exists to
             * make somebody justify — this one cannot be.
             */
            'specialties' => $segment === PersonSegment::Vendors
                ? $directory->specialties()
                : [],
            // Paginated, always. PRD §3.4 puts hundreds of past clients in a
            // team, and the people index is the screen that meets that volume
            // first — 500 rows must never reach the DOM.
            'people' => $directory->paginate($segment, $search, $vendorFilters),
            'lifecycleStates' => PersonLifecycleState::options(),
        ]);
    }

    public function show(TeamMembership $membership, ActivityFeed $feed): Response
    {
        $this->authorize('view', $membership);

        /*
         * `roles.permissions`, not just `roles`: the badge asks
         * `isColleague()`, which walks the permissions — and without the
         * nested load that is a lazy query per role. Found by review on #162,
         * measured rather than argued.
         */
        $membership->load(['person', 'roles.permissions']);

        /*
         * Shaped by `ActivityFeed`, not here.
         *
         * Two things came of that. The rows now carry the deal a logged
         * contact was attached to, which S26 made possible and this screen
         * would otherwise have been the one place not to show. And the actor
         * name is resolved once for the page rather than once per row — this
         * was `$event->actor?->displayNameWithin($event->team)` inside a
         * `map()`, which is a `teams` lookup *and* a `team_memberships` lookup
         * per row, so up to a hundred queries on a fifty-event timeline.
         */
        /*
         * Through `visibleToViewer()`, because this screen does **not** go
         * through `ActivityFeed::query()` — a person's own timeline is
         * `forSubject()` with its own limit — and the per-viewer rules live
         * there rather than in a caller.
         *
         * The one that matters here is the deal-context rule: F2.5 logs a
         * contact against a person and *optionally* a deal, so a reader
         * holding `people.view` without `deals.view` was shown the deal a
         * contact was attached to and a link to a page answering 403.
         */
        $activity = $feed->visibleToViewer(
            ActivityEvent::query()->forSubject($membership->person),
        )->limit(50)->get();

        return Inertia::render('People/Show', [
            'membership' => PeopleDirectory::detail($membership),
            'activity' => $feed->rows($activity),
            // S26's optional deal attachment (F2.5). The modal on this screen
            // offers the deals this person is on, and nothing else.
            'deals' => PersonDeals::forMembership((string) $membership->getKey()),
            'lifecycleStates' => PersonLifecycleState::options(),
        ]);
    }

    public function store(StorePersonRequest $request, SavePerson $save): RedirectResponse
    {
        $membership = $save->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Person added.')]);

        return to_route('people.show', $membership);
    }

    public function update(UpdatePersonRequest $request, TeamMembership $membership, SavePerson $save): RedirectResponse
    {
        $save->update($membership, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Person updated.')]);

        return to_route('people.show', $membership);
    }

    /**
     * Soft delete, which is the 30-day recovery window PRD §9 requires. The
     * shared `Person` row is untouched — another team may still know them.
     */
    /**
     * Remove somebody from the directory — which is not the same act as
     * taking away their access, and must not become a way of doing it.
     *
     * Slice 1's fourth review found the two apart. This route and
     * `MemberController::revoke()` reach the same `team_memberships` row;
     * only the members screen asked `guardLastOwner()`, and only the members
     * screen wanted `team.members.manage`. A Team Member holds `people.manage`
     * and not the other, so the *lower*-privileged role could delete the last
     * Team Owner's membership here after being refused there — leaving a team
     * nobody could administer, no route in `/admin` to repair it, and nothing
     * in the audit log naming who did it.
     *
     * So the membership decides which act this is. One that carries access is
     * a revocation: it needs the access permission, it goes through
     * `RevokeMembership` so the last-owner rule and the audit entry come with
     * it, and it sets `revoked_at` rather than deleting, because PRD F1.3
     * keeps historical attribution. One that carries none is an ordinary
     * contact, and removing them is what this screen is for.
     */
    public function destroy(TeamMembership $membership, RevokeMembership $revoke, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $membership);

        if ($membership->carriesAccess()) {
            $this->authorize('manageAccess', $membership);

            // Already revoked: `handle()` is idempotent, so nothing is
            // re-stamped and nothing is audited twice. Say which happened
            // rather than reporting an act that did not take place.
            $alreadyRevoked = $membership->isRevoked();

            $revoke->handle($membership);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $alreadyRevoked ? __('Access was already revoked.') : __('Access revoked.'),
            ]);

            return to_route('people.index');
        }

        $membership->delete();

        $audit->record(
            action: 'membership.removed',
            auditable: $membership,
            teamId: $membership->team_id,
            before: ['person_id' => $membership->person_id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Person removed from this team.')]);

        return to_route('people.index');
    }

    /**
     * Who this team knows, for the shell's log-contact modal (S26).
     *
     * S26 is reachable from the person record, from a deal, and from the
     * global shell. The first two already know who the contact was with; the
     * third does not, so it needs a search — and it needs each candidate's
     * deals with them, because the deal attachment is optional and offering it
     * must not cost a second round trip once somebody is picked.
     *
     * `viewAny`, not `create`: this reads the directory, and reading the
     * directory is what `people.view` is for. The write it feeds is authorized
     * separately by `ContactLogController`.
     */
    public function candidates(Request $request, PeopleDirectory $directory): JsonResponse
    {
        $this->authorize('viewAny', TeamMembership::class);

        /*
         * The directory's own search, not a second one — the same reason
         * `ParticipantController::candidates()` reaches for it. A
         * re-implementation here would drift on the null-surname `coalesce`
         * within a month, and this one is a phone-in-the-car surface where a
         * dropped row is a contact that never gets logged.
         */
        $memberships = $directory
            ->query(PersonSegment::All, trim((string) $request->query('q', '')))
            ->whereNull('team_memberships.revoked_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        $deals = PersonDeals::forMemberships($memberships->modelKeys());

        return response()->json([
            'candidates' => $memberships->map(fn (TeamMembership $membership): array => [
                'id' => $membership->getKey(),
                'name' => $membership->fullName(),
                'email' => $membership->email,
                'deals' => $deals[(string) $membership->getKey()] ?? [],
            ])->values()->all(),
        ]);
    }

    /**
     * S32's duplicate-email state, answered while somebody is still typing.
     *
     * *"Duplicate email produces a warning and an offer to open the existing
     * record, not a hard failure."* The lookup only ever reports a membership
     * **this team already holds** — reporting a match from the shared `people`
     * table would confirm that some other team knows that address.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('create', TeamMembership::class);

        $email = trim((string) $request->query('email', ''));

        /*
         * The address on the membership, which is the only one this team has
         * (#140). It could never have reported a match from another team's
         * directory, and now there is not even a shared row to look in.
         *
         * A revoked membership is excluded: "you already have them" about
         * somebody whose access was removed is an offer that goes nowhere.
         */
        $membership = $email === '' ? null : TeamMembership::query()
            ->whereNull('revoked_at')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->first();

        return response()->json([
            'duplicate' => $membership === null ? null : [
                'id' => $membership->getKey(),
                'name' => $membership->fullName(),
                'url' => route('people.show', $membership),
            ],
        ]);
    }
}
