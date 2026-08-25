<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Deal;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * S07 — the global search overlay (PRD §4.9 F9.3 · #82).
 *
 * ## Team-scoped is a security requirement, not a convenience
 *
 * #82 says it plainly: *"search is the classic place tenancy leaks, because it
 * is the one query that deliberately spans every table."* So every query here
 * is an **ordinary Eloquent query** on a `BelongsToTeam` model, with no
 * `withoutTeamScope()`, no raw table access, and no union that could shed a
 * scope on the way through. The global scope fails closed; this file's job is
 * to give it nothing to fail open on. `tests/Isolation` proves the property
 * rather than this docblock asserting it.
 *
 * ## Postgres `like`, not a search service
 *
 * PRD §8.1 argues against premature infrastructure and §14.2 A5 assumes a sole
 * developer. At v1 scale — 200 teams, and a team holding twenty-five active
 * deals against hundreds of past clients — a lowered `like` on indexed name
 * columns is the honest first answer. #82 says to revisit *"only if the result
 * quality is genuinely inadequate"*, which is a judgement about real use and
 * not something to pre-empt here.
 *
 * ## Documents are not searched yet
 *
 * They are Slice 3, by filename and category, and never by content — PRD §10
 * settles what this product retains. The group is absent rather than empty:
 * a heading with nothing under it reads as "we found none of yours", which
 * would be a lie about a feature that does not exist.
 */
final class GlobalSearch
{
    /** Per group, so no one kind of thing can crowd out the others. */
    private const PER_GROUP = 5;

    /**
     * Below this, a query matches too much to be worth running.
     *
     * Two characters against hundreds of past clients returns a page of
     * everything, which is slower and less useful than the empty state that
     * tells somebody to keep typing.
     */
    public const MINIMUM_LENGTH = 2;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function for(string $term, ?Person $person = null): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MINIMUM_LENGTH) {
            return [];
        }

        $like = '%'.mb_strtolower($term).'%';

        /*
         * **Each group behind its own permission.**
         *
         * One `deals.view` check for the whole box was defensible while the
         * five shipped roles were the only roles: each of them either held all
         * three view permissions or none. S75 (#88) ended that — a team
         * composes a role from the catalogue now, and *"deals but not the
         * client directory"* is an ordinary thing to build. One check would
         * have handed that person every client name and address in the team
         * through a search box, which is exactly the hole #82 calls search
         * *"the classic place tenancy leaks"* about, one axis over: not the
         * wrong team, the wrong colleague.
         *
         * A permission somebody lacks removes the **group**, not the results,
         * so nothing tells them a group exists and is empty.
         */
        $groups = array_filter([
            ['type' => 'deal', 'label' => 'Deals', 'permission' => Permissions::VIEW_DEALS],
            ['type' => 'person', 'label' => 'People', 'permission' => Permissions::VIEW_PEOPLE],
            ['type' => 'property', 'label' => 'Properties', 'permission' => Permissions::VIEW_PROPERTIES],
        ], fn (array $group): bool => self::mayRead($person, (string) $group['permission']));

        $groups = array_map(fn (array $group): array => [
            'type' => $group['type'],
            'label' => $group['label'],
            'results' => match ($group['type']) {
                'deal' => self::deals($like),
                'person' => self::people($like),
                default => self::properties($like),
            },
        ], $groups);

        // A group with nothing in it is not rendered: #82 asks for results
        // "grouped by type with the type visible", and three empty headings
        // above one result buries it.
        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['results'] !== [],
        ));
    }

    /**
     * A null person is the pre-#88 behaviour and is used by nothing but tests
     * that predate the split; the controller always passes one.
     */
    private static function mayRead(?Person $person, string $permission): bool
    {
        return ! $person instanceof Person
            || in_array($permission, Permissions::grantedTo($person), true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function deals(string $like): array
    {
        return Deal::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(coalesce(name, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(generated_name, \'\')) like ?', [$like]))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Deal $deal): array => [
                'id' => $deal->getKey(),
                // `displayName()` decides between the typed name and the
                // derived one — the same rule every other screen reads.
                'label' => $deal->displayName(),
                'meta' => $deal->state->label(),
                'url' => '/deals/'.$deal->getKey(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function people(string $like): array
    {
        return TeamMembership::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(first_name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(last_name, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$like]))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (TeamMembership $membership): array => [
                'id' => $membership->getKey(),
                'label' => $membership->fullName(),
                'meta' => $membership->email,
                'url' => '/people/'.$membership->getKey(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function properties(string $like): array
    {
        return Property::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(coalesce(street, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(city, \'\')) like ?', [$like]))
            ->orderBy('street')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Property $property): array => [
                'id' => $property->getKey(),
                'label' => $property->displayName(),
                'meta' => self::locality($property),
                'url' => '/properties/'.$property->getKey(),
            ])
            ->all();
    }

    private static function locality(Property $property): ?string
    {
        $parts = array_filter([$property->city, $property->state_code]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * What the overlay shows before anybody types (#82).
     *
     * *"Recent items when the query is empty — the fastest search is the one
     * you do not have to type."* The team's most recently touched deals, which
     * is the thing somebody is overwhelmingly looking for.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(): array
    {
        return [
            [
                'type' => 'deal',
                'label' => 'Recent deals',
                'results' => Deal::query()
                    ->orderByDesc('updated_at')
                    ->limit(self::PER_GROUP)
                    ->get()
                    ->map(fn (Deal $deal): array => [
                        'id' => $deal->getKey(),
                        'label' => $deal->displayName(),
                        'meta' => $deal->state->label(),
                        'url' => '/deals/'.$deal->getKey(),
                    ])
                    ->all(),
            ],
        ];
    }
}
