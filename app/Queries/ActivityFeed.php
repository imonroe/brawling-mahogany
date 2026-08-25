<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ActivityCategory;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Activity\ActorDirectory;
use App\Support\Permissions;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The team activity feed's query (Screen Inventory S12, PRD §4.9 F9.4).
 *
 * ## Why a cursor and not a page number
 *
 * The feed loads more as you scroll, and it is the one list in the product
 * whose *first* row changes while you read it — a teammate advancing a stage
 * inserts at the top. Offset pagination under an insert shows page two's first
 * row twice and drops the row that moved onto it. A cursor keyed on
 * `(occurred_at, id)` does not, and it is the shape the
 * `(team_id, occurred_at)` index was built for.
 *
 * ## Names are resolved once, not once per row
 *
 * Every name on this screen — the actor, the person a call was logged against,
 * the property, the deal — is a lookup that a `map()` would make per row.
 * They are collected and resolved in one query each, so a page of fifty costs
 * the same number of queries as a page of five.
 * `tests/Performance/ActivityFeedBudgetTest.php` holds it to that.
 */
final class ActivityFeed
{
    public const PER_PAGE = 25;

    /**
     * @return CursorPaginator<int, ActivityEvent>
     */
    public function paginate(ActivityCategory $category, ?string $cursor = null): CursorPaginator
    {
        return $this->query($category)
            ->cursorPaginate(self::PER_PAGE, cursor: $cursor)
            ->withQueryString();
    }

    /**
     * @return Builder<ActivityEvent>
     */
    public function query(ActivityCategory $category): Builder
    {
        /*
         * **Newest first, here rather than in a caller.**
         *
         * The ordering lived in `paginate()`, which is the only place it was
         * needed while `/activity` was the only screen. S10's panel calls
         * `query()->limit(8)->get()` — so it took whatever eight rows Postgres
         * returned first, which is insertion order, which is the **oldest**
         * eight. The panel's own empty state promises "newest first".
         *
         * `id` breaks the tie because `occurred_at` is a timestamp two events
         * can share — a contact log and the stage advance somebody recorded in
         * the same second — and a cursor paginator needs a total order or it
         * repeats a row across pages. ULIDs sort by creation time, so the tie
         * is broken the way a reader expects rather than arbitrarily.
         *
         * This is the same lesson the subject filter below carries, one line
         * up: a rule written into one caller is a rule the next caller is
         * written without.
         */
        $query = ActivityEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $prefixes = $category->prefixes();

        if ($prefixes !== []) {
            $query->where(function (Builder $inner) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $inner->orWhere('event_type', 'like', $prefix.'.%');
                }
            });
        }

        /*
         * The screen is gated on `people.view` (`ActivityEventPolicy::viewAny`),
         * and a feed is the one place where events about several parts of the
         * product arrive together — so the *screen's* gate is never enough.
         *
         * ## An allowlist, because an exclusion list fails open
         *
         * This was three `!=` rules, one per surface, and the docblock warned
         * that *"a subject type with no rule is visible to everyone who can
         * open the feed."* The warning came true twice: the person rule was
         * simply missing, and S10's dashboard panel then reused this query
         * behind a `deals.view` gate — so a composed *"deals but not the client
         * directory"* role read a client's name and a free-text contact note
         * on the screen they land on, with a link to a person page answering
         * 403. That is the leak #82 and #88 closed in the search box, one
         * screen over.
         *
         * So the shape is inverted. Every subject type the product writes is
         * named here with the permission it needs, and a type **not** named is
         * excluded — which is the rule ADR 0002 records for the purge cascade
         * after an exclusion list failed open there too. A fifth subject type
         * added in a later slice is invisible until somebody gives it a rule,
         * and `ActivityFeedIsolationTest` fails the build rather than waiting
         * for a reviewer to notice.
         *
         * ## Subjected to, not named after
         *
         * `property.linked`, `property.promoted`, `property.unlinked` and
         * `property.interest_recorded` are subjected to the **deal** — they are
         * things that happened to a deal, involving a property — so they take
         * the deal's permission, which is right: `DealPropertyPolicy::viewAny`
         * asks for `deals.view` and nothing else. Only `property.added` and
         * `property.status_changed` reach the property itself.
         *
         * ## And the deal-context rule, which is separate
         *
         * `deal_id` is set on every event belonging to a deal, whatever its
         * subject — F2.5 logs a contact against a person and *optionally* a
         * deal. So a person-subjected event can carry deal context, and
         * somebody without `deals.view` must not read it even though they hold
         * `people.view`.
         */
        return $this->visibleToViewer($query);
    }

    /**
     * The per-viewer rules, applied to **any** builder over `activity_events`.
     *
     * Public and separate from `query()` because S31 does not go through
     * `query()` — a person's own timeline is `forSubject($person)` with its
     * own limit — and a filter written into one caller is a filter the next
     * caller is written without. That sentence has now been proved twice on
     * this one class: once when S10 reused `query()` behind a different screen
     * gate, and once here, where a `people.view`-only reader saw the **deal**
     * a contact was logged against and a link to a page answering 403.
     *
     * @param  Builder<ActivityEvent>  $query
     * @return Builder<ActivityEvent>
     */
    public function visibleToViewer(Builder $query): Builder
    {
        $query->where(function (Builder $inner): void {
            foreach (self::subjectPermissions() as $morphClass => $permission) {
                if ($this->viewerCanSee($permission)) {
                    $inner->orWhere('subject_type', $morphClass);
                }
            }

            // Nothing visible at all: an impossible predicate rather than an
            // unconstrained query, because an empty `where` group matches
            // everything.
            $inner->orWhereRaw('1 = 0');
        });

        /*
         * And the deal-context rule, which is separate from the subject one.
         *
         * `deal_id` is set on every event belonging to a deal, whatever its
         * subject — F2.5 logs a contact against a person and *optionally* a
         * deal. So a person-subjected event can carry deal context, and
         * somebody holding `people.view` without `deals.view` must not read
         * it.
         */
        if (! $this->viewerCanSeeDeals()) {
            $query->whereNull('deal_id');
        }

        return $query;
    }

    /**
     * Every subject type the feed can carry, and the permission it needs.
     *
     * The list is exhaustive by construction: `ActivityFeedIsolationTest`
     * reads every `subject:` argument in `app/` and fails when one resolves to
     * a class this map does not name. A type added without a rule is invisible
     * rather than public, which is the failure direction ADR 0002 asks for.
     *
     * `Workflow` takes `deals.view` because a workflow is a deal's process —
     * there is no separate permission for one, and `WorkflowPolicy` asks the
     * same key.
     *
     * @return array<string, string> morph class => permission key
     */
    public static function subjectPermissions(): array
    {
        return [
            (new Deal)->getMorphClass() => Permissions::VIEW_DEALS,
            (new Workflow)->getMorphClass() => Permissions::VIEW_DEALS,
            (new Person)->getMorphClass() => Permissions::VIEW_PEOPLE,
            (new Property)->getMorphClass() => Permissions::VIEW_PROPERTIES,
        ];
    }

    /**
     * A set of events, as a screen reads them.
     *
     * Takes any iterable rather than a paginator, because three screens render
     * this shape from three different queries — the feed's cursor page, one
     * person's timeline (S31), and the deal's own (S16) — and a second
     * hand-rolled mapping is how two of them end up disagreeing about whether
     * a row carries the deal it belongs to.
     *
     * @param  iterable<ActivityEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $events): array
    {
        /** @var \Illuminate\Support\Collection<int, ActivityEvent> $events */
        $events = collect($events);

        $actors = ActorDirectory::for($events);
        $people = $this->membershipsFor($events);
        $properties = $this->propertiesFor($events);
        $deals = $this->dealsFor($events);

        $rows = [];

        foreach ($events as $event) {
            $deal = $event->deal_id === null ? null : ($deals[$event->deal_id] ?? null);

            $rows[] = [
                'id' => $event->getKey(),
                'eventType' => $event->event_type,
                'summary' => $event->summary,
                'source' => $event->source,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'actorName' => $actors->nameOf($event),
                'subject' => $this->subject($event, $people, $properties, $deals),
                'deal' => $deal === null
                    ? null
                    : ['label' => $deal->displayName(), 'url' => route('deals.show', $deal)],
                /*
                 * Only the note, not the whole payload. The payload is an open
                 * bag that later slices will put internal values in, and a
                 * screen that renders all of it renders whatever lands there
                 * next.
                 */
                'note' => is_string($event->payload['note'] ?? null) ? $event->payload['note'] : null,
                // PRD §6.3's contact type, which is what picks the row's icon
                // — a phone and an envelope are legible at a glance in a way
                // "Phone call" and "Email" at 14px are not.
                'contactType' => is_string($event->payload['contact_type'] ?? null)
                    ? $event->payload['contact_type']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * The thing an event happened to, named and — where a screen exists for it
     * — linked.
     *
     * A deal subject is linked now that S15 (#75) exists — that comment was
     * written when it did not, and landing the two changes together falsified
     * it without touching the line. A clean textual merge is not a correct
     * merge, which is the second instance of that this PR carries.
     *
     * @param  array<string, TeamMembership>  $people
     * @param  array<string, Property>  $properties
     * @param  array<string, Deal>  $deals
     * @return array{label: string, url: string|null}|null
     */
    private function subject(ActivityEvent $event, array $people, array $properties, array $deals): ?array
    {
        if ($event->subject_type === (new Person)->getMorphClass()) {
            $membership = $people[$event->subject_id] ?? null;

            return $membership === null
                ? null
                : ['label' => $membership->fullName(), 'url' => route('people.show', $membership)];
        }

        if ($event->subject_type === (new Property)->getMorphClass()) {
            $property = $properties[$event->subject_id] ?? null;

            return $property === null
                ? null
                : ['label' => $property->displayName(), 'url' => route('properties.show', $property)];
        }

        if ($event->subject_type === (new Deal)->getMorphClass()) {
            $deal = $deals[$event->subject_id] ?? null;

            return $deal === null
                ? null
                : ['label' => $deal->displayName(), 'url' => route('deals.show', $deal)];
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActivityEvent>  $events
     * @return array<string, TeamMembership> person id => membership
     */
    private function membershipsFor(\Illuminate\Support\Collection $events): array
    {
        $ids = $events
            ->where('subject_type', (new Person)->getMorphClass())
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return TeamMembership::query()
            ->whereIn('person_id', $ids->all())
            ->get()
            ->keyBy(fn (TeamMembership $membership): string => (string) $membership->person_id)
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActivityEvent>  $events
     * @return array<string, Property>
     */
    private function propertiesFor(\Illuminate\Support\Collection $events): array
    {
        $ids = $events
            ->where('subject_type', (new Property)->getMorphClass())
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Property::query()->whereIn('id', $ids->all())->get()->keyBy('id')->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActivityEvent>  $events
     * @return array<string, Deal>
     */
    private function dealsFor(\Illuminate\Support\Collection $events): array
    {
        $ids = $events->pluck('deal_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Deal::query()->whereIn('id', $ids->all())->get()->keyBy('id')->all();
    }

    private function viewerCanSeeDeals(): bool
    {
        return $this->viewerCanSee(Permissions::VIEW_DEALS);
    }

    private function viewerCanSee(string $permission): bool
    {
        return in_array(
            $permission,
            Permissions::grantedTo(auth()->user() instanceof Person ? auth()->user() : null),
            strict: true,
        );
    }
}
