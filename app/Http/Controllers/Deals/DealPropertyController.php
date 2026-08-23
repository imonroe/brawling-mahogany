<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\DealSide;
use App\Enums\PropertyInterest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\LinkPropertyRequest;
use App\Http\Requests\Deals\UpdateDealPropertyRequest;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Queries\PropertyDirectory;
use App\Support\Properties\PropertyDeals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deal properties (Screen Inventory S20 · PRD §4.3 F3.4, F3.5 · issue #62).
 *
 * ## The two sides of a deal are genuinely different screens
 *
 * A **sell-side** deal has one house and has it from the start; the screen is
 * a header. A **buy-side** deal has twelve, none of them the subject until an
 * offer is accepted, each carrying an opinion that changes across showings —
 * the screen is a ranked list. Issue #62 names both, and the difference is why
 * `interest_status` is buyer-side only and why `link()` does not make a
 * buyer's first house the subject.
 *
 * ## Promotion is a rename
 *
 * IA §10 derives a deal's name from its subject property's street, so
 * promoting a candidate renames the deal — which is correct, and is the whole
 * reason it is an action somebody takes rather than a side effect of linking.
 * A typed name survives it: `NameDeal` writes `generated_name` and never
 * `name`, which is issue #62's *"must not overwrite a name the user has
 * manually edited."*
 */
class DealPropertyController extends Controller
{
    public function index(Deal $deal): Response
    {
        $this->authorize('viewAny', [DealProperty::class, $deal]);

        $deal->load(['dealType', 'propertyLinks.property']);

        $isBuySide = $deal->dealType->side === DealSide::Buy;

        /*
         * Subject first, then the agent's ranking. `Deal::propertyLinks()`
         * orders by `is_subject` and `created_at`; the rank is what S20 lets
         * somebody set, so it takes precedence over arrival order among the
         * candidates.
         */
        $links = $deal->propertyLinks
            ->sortBy([
                fn (DealProperty $link, DealProperty $other): int => ($other->is_subject ? 1 : 0) <=> ($link->is_subject ? 1 : 0),
                fn (DealProperty $link, DealProperty $other): int => $link->sort_order <=> $other->sort_order,
            ])
            ->values();

        return Inertia::render('Deals/Properties', [
            'deal' => [
                'id' => $deal->getKey(),
                'name' => $deal->displayName(),
                'sideLabel' => $deal->dealType->side->label(),
                'isBuySide' => $isBuySide,
                // #62: promoting renames the deal — unless somebody typed one,
                // in which case the screen should say so rather than implying
                // a rename that will not be visible.
                'hasManualName' => $deal->hasManualName(),
            ],
            'links' => $links->map(fn (DealProperty $link): array => [
                'id' => $link->getKey(),
                'propertyId' => $link->property_id,
                'isSubject' => $link->is_subject,
                'interestStatus' => $link->interest_status?->value,
                'property' => $link->property instanceof Property
                    ? PropertyDirectory::row($link->property)
                    : null,
            ])->all(),
            'interestStatuses' => PropertyInterest::options(),
        ]);
    }

    /**
     * Properties this deal is not already about, for the picker.
     *
     * The mirror of `Properties\PropertyDealController::candidates()`, and it
     * reuses `PropertyDirectory` for the same reason that one reuses the
     * people directory: a second search re-implemented here drifts, and #60
     * proved it by dropping rows with a null surname.
     */
    public function candidates(Request $request, Deal $deal, PropertyDirectory $directory): JsonResponse
    {
        $this->authorize('create', [DealProperty::class, $deal]);

        $properties = $directory
            ->query(null, trim((string) $request->query('q', '')))
            ->whereDoesntHave('dealLinks', fn ($links) => $links->where('deal_id', $deal->getKey()))
            ->orderBy('city')
            ->orderBy('street')
            ->orderBy('id')
            ->limit(20)
            ->get();

        return response()->json([
            'properties' => $properties->map(
                fn (Property $property): array => PropertyDirectory::row($property),
            )->values()->all(),
        ]);
    }

    public function store(LinkPropertyRequest $request, Deal $deal, PropertyDeals $deals): RedirectResponse
    {
        /** @var Property $property */
        $property = Property::query()->whereKey($request->validated('property_id'))->firstOrFail();

        $deals->link($property, $deal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property linked.')]);

        return to_route('deals.properties.index', $deal);
    }

    public function update(
        UpdateDealPropertyRequest $request,
        Deal $deal,
        DealProperty $propertyLink,
        PropertyDeals $deals,
    ): RedirectResponse {
        $deals->describe($propertyLink, $request->changes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Updated.')]);

        return to_route('deals.properties.index', $deal);
    }

    /** Make this candidate the property the deal is about. */
    public function promote(Deal $deal, DealProperty $propertyLink, PropertyDeals $deals): RedirectResponse
    {
        $this->authorize('promote', $propertyLink);

        $deals->promote($propertyLink);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject property set.')]);

        return to_route('deals.properties.index', $deal);
    }

    /**
     * The agent's ranking (#62: *"`sort_order` exists so an agent can rank
     * candidates"*).
     */
    public function rank(Request $request, Deal $deal, PropertyDeals $deals): RedirectResponse
    {
        $this->authorize('create', [DealProperty::class, $deal]);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200', 'list'],
            'order.*' => ['required', 'string'],
        ]);

        $deals->rank($deal, $validated['order']);

        return to_route('deals.properties.index', $deal);
    }

    /** IA §7: **Remove** detaches, **Delete** destroys. This detaches. */
    public function remove(Deal $deal, DealProperty $propertyLink, PropertyDeals $deals): RedirectResponse
    {
        $this->authorize('remove', $propertyLink);

        $deals->unlink($propertyLink);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property removed.')]);

        return to_route('deals.properties.index', $deal);
    }
}
