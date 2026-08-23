<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ActivityCategory;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
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
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, cursor: $cursor)
            ->withQueryString();
    }

    /**
     * @return Builder<ActivityEvent>
     */
    public function query(ActivityCategory $category): Builder
    {
        $query = ActivityEvent::query();

        $prefixes = $category->prefixes();

        if ($prefixes !== []) {
            $query->where(function (Builder $inner) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $inner->orWhere('event_type', 'like', $prefix.'.%');
                }
            });
        }

        /*
         * The screen is gated on `people.view` (ActivityEventPolicy::viewAny),
         * and a feed is the one place where events about several parts of the
         * product arrive together.
         *
         * **One rule, and it is only about deals.** A deal-context event needs
         * `deals.view`; `deal_id` is set on every event that belongs to a deal
         * (`RecordActivity` fills it from the subject when the subject is a
         * deal), so the whole rule is one `whereNull`.
         *
         * Nothing else here is filtered, and that is deliberate rather than
         * unfinished. Everything a feed can currently carry is either about a
         * deal — covered — or about a person, a property or a vendor, all of
         * which `people.view` already opens. The next event type that is
         * neither is the one that needs a second rule; there is no general
         * per-surface filter to fall through to, so it will have to be
         * written.
         *
         * The shipped roles all hold `deals.view` alongside `people.view`, so
         * today this changes nothing; a team's own composed role (PRD F2.3)
         * is what it exists for.
         */
        if (! $this->viewerCanSeeDeals()) {
            $query->whereNull('deal_id');
        }

        return $query;
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
        return in_array(
            Permissions::VIEW_DEALS,
            Permissions::grantedTo(auth()->user() instanceof Person ? auth()->user() : null),
            strict: true,
        );
    }
}
