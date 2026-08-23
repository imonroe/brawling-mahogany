<?php

declare(strict_types=1);

namespace App\Http\Controllers\Properties;

use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\LinkDealRequest;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Support\Properties\PropertyDeals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The deals a property is on (S36 · issue #61).
 *
 * The property side of `deal_properties`. #62 builds the deal side — the
 * properties tab, the interest vocabulary, and the interaction that moves the
 * subject — and both use `PropertyDeals`, so the rule about what happens to a
 * deal's name lives in one place rather than in whichever screen was written
 * first.
 */
class PropertyDealController extends Controller
{
    /**
     * Deals this property is not already on, for the picker.
     *
     * Open deals first and closed ones after, rather than only open ones: a
     * house being added to the record of a sale that closed last month is
     * ordinary catch-up work, and a picker that hid it would send somebody
     * looking for a bug.
     */
    public function candidates(Request $request, Property $property): JsonResponse
    {
        $this->authorize('link', $property);

        $search = trim((string) $request->query('q', ''));

        $deals = Deal::query()
            ->whereDoesntHave('propertyLinks', fn ($links) => $links->where('property_id', $property->getKey()))
            ->with('dealType')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($where) => $where
                    ->where('name', 'ilike', $term)
                    ->orWhere('generated_name', 'ilike', $term));
            })
            ->orderByRaw("case when state = 'active' then 0 else 1 end")
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get();

        return response()->json([
            'deals' => $deals->map(fn (Deal $deal): array => [
                'id' => $deal->getKey(),
                'name' => $deal->displayName(),
                'state' => $deal->state->value,
                'sideLabel' => $deal->dealType->side->label(),
            ])->values()->all(),
        ]);
    }

    public function store(LinkDealRequest $request, Property $property, PropertyDeals $deals): RedirectResponse
    {
        /** @var Deal $deal */
        $deal = Deal::query()->whereKey($request->validated('deal_id'))->firstOrFail();

        $deals->link($property, $deal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal linked.')]);

        return to_route('properties.show', $property);
    }

    /** IA §7: **Remove** detaches, **Delete** destroys. This detaches. */
    public function remove(Property $property, DealProperty $dealLink, PropertyDeals $deals): RedirectResponse
    {
        $this->authorize('link', $property);

        $deals->unlink($dealLink);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal removed.')]);

        return to_route('properties.show', $property);
    }
}
