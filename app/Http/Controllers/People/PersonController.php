<?php

declare(strict_types=1);

namespace App\Http\Controllers\People;

use App\Actions\People\SavePerson;
use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StorePersonRequest;
use App\Http\Requests\People\UpdatePersonRequest;
use App\Models\ActivityEvent;
use App\Models\TeamMembership;
use App\Queries\PeopleDirectory;
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

        return Inertia::render('People/Index', [
            'segment' => $segment->value,
            'segmentCounts' => $directory->segmentCounts(),
            'emptyMessage' => $segment->emptyMessage(),
            'search' => $search,
            // Paginated, always. PRD §3.4 puts hundreds of past clients in a
            // team, and the people index is the screen that meets that volume
            // first — 500 rows must never reach the DOM.
            'people' => $directory->paginate($segment, $search),
            'lifecycleStates' => PersonLifecycleState::options(),
        ]);
    }

    public function show(TeamMembership $membership): Response
    {
        $this->authorize('view', $membership);

        $membership->load(['person', 'roles']);

        return Inertia::render('People/Show', [
            'membership' => PeopleDirectory::detail($membership),
            'activity' => ActivityEvent::query()
                ->forSubject($membership->person)
                ->with('actor:id,first_name,last_name')
                ->limit(50)
                ->get()
                ->map(fn (ActivityEvent $event): array => [
                    'id' => $event->getKey(),
                    'eventType' => $event->event_type,
                    'summary' => $event->summary,
                    'source' => $event->source,
                    'occurredAt' => $event->occurred_at->toIso8601String(),
                    'payload' => $event->payload,
                    'actorName' => $event->actor?->fullName(),
                ])->all(),
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
    public function destroy(TeamMembership $membership): RedirectResponse
    {
        $this->authorize('delete', $membership);

        $membership->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Person removed from this team.')]);

        return to_route('people.index');
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

        $membership = $email === '' ? null : TeamMembership::query()
            ->whereHas('person', fn ($query) => $query->whereRaw('lower(email) = ?', [mb_strtolower($email)]))
            ->with('person:id,first_name,last_name')
            ->first();

        return response()->json([
            'duplicate' => $membership === null ? null : [
                'id' => $membership->getKey(),
                'name' => $membership->person->fullName(),
                'url' => route('people.show', $membership),
            ],
        ]);
    }
}
