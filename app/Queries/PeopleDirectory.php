<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PersonLifecycleState;
use App\Enums\PersonSegment;
use App\Models\DealParticipant;
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
    /**
     * @param  array{specialty?: string, area?: string}  $vendorFilters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        PersonSegment $segment,
        string $search = '',
        array $vendorFilters = [],
    ): LengthAwarePaginator {
        return $this->query($segment, $search, $vendorFilters)
            // Only the login is still needed from `people` — S31's "no login"
            // state asks whether they can sign in, and nothing else does.
            ->with('person:id,password')
            /*
             * `carriesAccess()` walks roles → permissions and the badge reads
             * the role name, so both are loaded once for the page rather than
             * twice per row. `PeopleIndexBudgetTest` is what holds it: 25 rows
             * cost what 2 rows cost.
             */
            ->with('roles.permissions')
            /*
             * **Last used** (S34 · #83), derived and never stored.
             *
             * F2.6 calls it *"the most useful column and the one most likely
             * to be stale if duplicated"* — a stager engaged on a deal in
             * March and again in July has one true answer, and a column
             * somebody has to remember to write has two. Through
             * `DealParticipant::query()` so the tenancy and soft-delete scopes
             * both apply; the correlation is the only condition written here.
             *
             * One subquery for the page rather than one per row, which is
             * what `PeopleIndexBudgetTest`'s growth case holds it to.
             */
            ->addSelect([
                'last_used_at' => DealParticipant::query()
                    ->selectRaw('max(deal_participants.created_at)')
                    ->whereColumn('deal_participants.team_membership_id', 'team_memberships.id'),
            ])
            ->orderBy('team_memberships.last_name')
            ->orderBy('team_memberships.first_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (TeamMembership $membership): array => self::row($membership));
    }

    /**
     * Every specialty this team has typed, sorted, for S34's filter (#83).
     *
     * `vendor_specialties` is free text by IA §13.3's decision, so there is no
     * lookup table to read and the honest list is the one in use. One query
     * over the vendors, unnested in PHP — the alternative is a `jsonb_array_
     * elements` lateral, which is more SQL to keep working for a list that is
     * a dozen strings long.
     *
     * @return list<string>
     */
    public function specialties(): array
    {
        $all = TeamMembership::query()
            ->where('is_vendor', true)
            ->whereNotNull('vendor_specialties')
            ->pluck('vendor_specialties')
            ->flatMap(fn (mixed $value): array => is_array($value) ? $value : [])
            ->filter(fn (mixed $one): bool => is_string($one) && $one !== '')
            ->unique()
            ->sort()
            ->values();

        /** @var list<string> */
        return $all->all();
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
     * @param  array{specialty?: string, area?: string}  $vendorFilters
     * @return Builder<TeamMembership>
     */
    public function query(
        PersonSegment $segment,
        string $search = '',
        array $vendorFilters = [],
    ): Builder {
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
            /*
             * `notColleagues()`, which is `isColleague()` in SQL — the same
             * question the badge beside the row asks (#162).
             *
             * This used to be `notCarryingAccess()`, which revocation does not
             * enter. So a former colleague recorded as the past client they
             * now are was drawn as a contact and filtered as a colleague, and
             * appeared on no segment but All: the reclassification was
             * editable, audited, and invisible.
             *
             * The lifecycle filter alone would very nearly do it now that a
             * colleague's `status` is null. Very nearly is the problem: it
             * would put anybody whose row predates the backfill, or whose
             * roles were attached by something that did not think about the
             * column, back on the Clients tab.
             */
            PersonSegment::Clients => $query
                ->whereIn('team_memberships.status', [
                    PersonLifecycleState::Active->value,
                    PersonLifecycleState::PastClient->value,
                ])
                ->notColleagues(),
            PersonSegment::Vendors => $query->where('team_memberships.is_vendor', true),
            // Same pair, same reason. The segment is how a team decides who
            // to chase, and a colleague is not on that list.
            PersonSegment::Leads => $query
                ->where('team_memberships.status', PersonLifecycleState::Lead->value)
                ->notColleagues(),
            PersonSegment::Team => $query->colleagues(),
        };

        /*
         * The vendor filters, and only on the segment that has them (#83).
         *
         * PRD §5.9 step 4 is the whole value of S34: *"filtering the directory
         * by specialty surfaces him with his rating and history"*, and a
         * directory that cannot be asked *"who stages, in this area, that we
         * liked"* is a contact list. Ignored on every other segment rather
         * than refused — a stale `?specialty=` in a bookmark should not empty
         * the Clients tab.
         */
        if ($segment === PersonSegment::Vendors) {
            $specialty = trim($vendorFilters['specialty'] ?? '');
            $area = trim($vendorFilters['area'] ?? '');

            if ($specialty !== '') {
                // `jsonb` containment, so "staging" matches the tag and not a
                // vendor whose service area happens to mention staging.
                $query->whereJsonContains('team_memberships.vendor_specialties', $specialty);
            }

            if ($area !== '') {
                $query->whereRaw(
                    'lower(coalesce(team_memberships.vendor_service_area, \'\')) like ?',
                    ['%'.mb_strtolower($area).'%'],
                );
            }
        }

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
            /*
             * Nullable, and null is not "unknown" — it is *this person has no
             * place on the client lifecycle* (#162). A colleague holds it; so
             * does a former colleague nobody has reclassified yet. A screen
             * with nothing to say about somebody says nothing.
             */
            'status' => $membership->status?->value,
            /*
             * Three independent facts, each drawn when it is true, rather than
             * one badge standing in for all of them (#162).
             *
             * `carriesAccess` is what the role badges hang on, so the People
             * row says what `/settings/members` says about the same person —
             * including after revocation, where the first fix swapped the
             * roles out for a lifecycle nobody had chosen and reproduced the
             * original bug on a different row.
             *
             * `isColleague` is `carriesAccess` **and** not revoked, and is the
             * question the form asks: whether the lifecycle is theirs to set.
             */
            'carriesAccess' => $membership->carriesAccess(),
            'isColleague' => $membership->isColleague(),
            /*
             * What the team calls them, for the badge a colleague gets
             * instead. `/settings/members` shows the same thing, so the two
             * screens describe somebody the same way.
             */
            'roles' => $membership->roles->map(fn ($role): string => $role->name)->values()->all(),
            'isVendor' => $membership->is_vendor,
            /*
             * The three cells S34's rows draw, and null for everybody else
             * (#83). Carried on the row rather than fetched by the segment,
             * because the Vendors tab is the same list narrowed — and a
             * separate shape for one segment is a second row type to keep in
             * step with this one.
             */
            'vendor' => $membership->is_vendor
                ? self::vendorSummary($membership, is_string($membership->getAttribute('last_used_at'))
                    ? $membership->getAttribute('last_used_at')
                    : null)
                : null,
            // S31's "no login" state. Most of this directory has none, and the
            // screen says so rather than implying an account exists.
            'hasLogin' => $person->hasCredentials(),
            'isRevoked' => $membership->isRevoked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function vendorSummary(TeamMembership $membership, ?string $lastUsedAt): array
    {
        return [
            'specialties' => $membership->vendor_specialties ?? [],
            'rating' => $membership->vendor_rating,
            'serviceArea' => $membership->vendor_service_area,
            'lastUsedAt' => $lastUsedAt,
        ];
    }

    /** When this team last put them on a deal, or null for never. */
    private static function lastUsedFor(TeamMembership $membership): ?string
    {
        $at = DealParticipant::query()
            ->where('team_membership_id', $membership->getKey())
            ->max('created_at');

        return is_string($at) ? $at : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(TeamMembership $membership): array
    {
        return [
            ...self::row($membership),
            'notes' => $membership->notes,
            /*
             * No keyed `roles` shape here any more. It carried `key` and
             * `name` for every role and **nothing rendered either** — the same
             * finding CLAUDE.md records about an eager-load: name the cell
             * that reads it, and if there is not one, that is the finding.
             * `row()` now carries the names, which is what the badge draws.
             */
            /*
             * The row's summary plus what only this screen shows, rather than
             * a second vendor shape (#83). One vocabulary for a vendor: the
             * index draws four of these fields and S31 draws six, and a key
             * carrying two shapes is the drift this codebase keeps recording.
             *
             * `lastUsedAt` is asked directly here because `paginate()`'s
             * subquery is not in play for a single row — one query for one
             * membership, rather than a column selected on a page of
             * twenty-five.
             */
            'vendor' => [
                ...self::vendorSummary($membership, self::lastUsedFor($membership)),
                'typicalCost' => $membership->vendor_typical_cost,
                'notes' => $membership->vendor_notes,
            ],
            'joinedAt' => $membership->joined_at?->toIso8601String(),
            'revokedAt' => $membership->revoked_at?->toIso8601String(),
        ];
    }
}
