<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Models\TeamMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The People index's query, in one place (Screen Inventory S30).
 *
 * *"The 500-row requirement is real."* PRD §3.4 puts twenty-five active deals
 * and hundreds of past clients in a team, so the index paginates, selects only
 * the columns the row renders, and eager-loads the person rather than issuing
 * one query per row. `tests/Performance` holds it to that.
 */
final class PeopleDirectory
{
    public const PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(PersonSegment $segment, string $search = ''): LengthAwarePaginator
    {
        return $this->query($segment, $search)
            // Only the login is still needed from `people` — S31's "no login"
            // state asks whether they can sign in, and nothing else does.
            ->with('person:id,password')
            ->orderBy('team_memberships.last_name')
            ->orderBy('team_memberships.first_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (TeamMembership $membership): array => self::row($membership));
    }

    /**
     * How many people sit behind each tab, so the segmented control can say so.
     *
     * @return array<int, array{value: string, label: string, count: int}>
     */
    public function segmentCounts(): array
    {
        return array_map(
            fn (PersonSegment $segment): array => [
                'value' => $segment->value,
                'label' => $segment->label(),
                'count' => $this->query($segment)->count(),
            ],
            PersonSegment::cases(),
        );
    }

    /**
     * @return Builder<TeamMembership>
     */
    public function query(PersonSegment $segment, string $search = ''): Builder
    {
        /*
         * No join any more (#140).
         *
         * This used to join `people` because the sort and the search were on
         * columns that lived there. They live here now, so the whole directory
         * is one table — which is faster, and is the shape
         * `team_memberships_name_index` was written for.
         *
         * A deleted account no longer needs filtering out either: deleting one
         * revokes its memberships (`Person::revokeEveryMembership`), and a
         * revoked membership is already excluded where that matters.
         */
        $query = TeamMembership::query()->select('team_memberships.*');

        $query = match ($segment) {
            PersonSegment::All => $query,
            PersonSegment::Clients => $query
                ->whereIn('team_memberships.status', [
                    PersonLifecycleState::Active->value,
                    PersonLifecycleState::PastClient->value,
                ])
                ->notCarryingAccess(),
            PersonSegment::Vendors => $query->where('team_memberships.is_vendor', true),
            PersonSegment::Leads => $query->where('team_memberships.status', PersonLifecycleState::Lead->value),
            PersonSegment::Team => $query
                ->whereNull('team_memberships.revoked_at')
                ->carryingAccess(),
        };

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function (Builder $inner) use ($term): void {
                $inner
                    ->whereRaw('lower(team_memberships.first_name) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(team_memberships.last_name, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(team_memberships.email, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(team_memberships.phone, \'\')) like ?', [$term]);
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(TeamMembership $membership): array
    {
        $person = $membership->person;

        return [
            'id' => $membership->getKey(),
            // This team's view of them (#140), not a shared record.
            'firstName' => $membership->first_name,
            'lastName' => $membership->last_name,
            'email' => $membership->email,
            'phone' => $membership->phone,
            'status' => $membership->status->value,
            'isVendor' => $membership->is_vendor,
            // S31's "no login" state. Most of this directory has none, and the
            // screen says so rather than implying an account exists.
            'hasLogin' => $person->hasCredentials(),
            'isRevoked' => $membership->isRevoked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(TeamMembership $membership): array
    {
        return [
            ...self::row($membership),
            'notes' => $membership->notes,
            'roles' => $membership->roles->map(fn ($role): array => [
                'key' => $role->key,
                'name' => $role->name,
            ])->all(),
            'vendor' => [
                'specialties' => $membership->vendor_specialties ?? [],
                'typicalCost' => $membership->vendor_typical_cost,
                'serviceArea' => $membership->vendor_service_area,
                'rating' => $membership->vendor_rating,
                'notes' => $membership->vendor_notes,
            ],
            'joinedAt' => $membership->joined_at?->toIso8601String(),
            'revokedAt' => $membership->revoked_at?->toIso8601String(),
        ];
    }
}
