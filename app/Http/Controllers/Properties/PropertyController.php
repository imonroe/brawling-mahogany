<?php

declare(strict_types=1);

namespace App\Http\Controllers\Properties;

use App\Actions\Properties\SaveProperty;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\StorePropertyRequest;
use App\Http\Requests\Properties\UpdatePropertyRequest;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Queries\PropertyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Properties (Screen Inventory S35, S36, S37 · PRD §4.3 F3.4 · issue #61).
 *
 * ## What is deliberately absent
 *
 * PRD §10: MLS listing data is licensed, and *"v1 stores links only, never
 * ingested listing content."* There is no import here, no fetch, no scrape,
 * and no column for a listing's title or price. Emily's complaint that a
 * competitor makes you upload an MLS sheet is, in the PRD's words, *"a symptom
 * of the same constraint, not a solvable product gap"* — so the answer is a
 * labelled link out (PRD §7.13), which is what `external_links` is.
 *
 * Photos are S38 and issue #62's neighbour, not this screen: the detail view
 * shows where the gallery will be rather than pretending it is not coming.
 */
class PropertyController extends Controller
{
    public function index(Request $request, PropertyDirectory $directory): Response
    {
        $this->authorize('viewAny', Property::class);

        $requested = (string) $request->query('status', 'all');
        $status = PropertyStatus::tryFrom($requested);
        $search = trim((string) $request->query('search', ''));

        return Inertia::render('Properties/Index', [
            // The unfiltered view is `all`, and so is anything unrecognised —
            // a hand-typed query string should not empty the screen.
            'status' => $status === null ? 'all' : $status->value,
            'statusCounts' => $directory->statusCounts($search),
            'search' => $search,
            'properties' => $directory->paginate($status, $search),
            'propertyTypes' => PropertyType::options(),
            'propertyStatuses' => PropertyStatus::options(),
        ]);
    }

    public function show(Request $request, Property $property): Response
    {
        $this->authorize('view', $property);

        $property->load([
            'externalLinks',
            'dealLinks.deal.dealType',
        ]);

        // `PropertyDirectory::row()` reports the count the index computes with
        // `withCount`; on this screen the links are loaded anyway, so it comes
        // from the collection rather than from a second query.
        $property->setAttribute('deal_links_count', $property->dealLinks->count());

        return Inertia::render('Properties/Show', [
            'property' => [
                ...PropertyDirectory::row($property),
                'parcelNumber' => $property->parcel_number,
                'yearBuilt' => $property->year_built,
                'notes' => $property->notes,
                'statusLabel' => $property->status->label(),
                'hasAddress' => $property->hasAddress(),
            ],
            'links' => $property->externalLinks->map(fn (ExternalLink $link): array => [
                'id' => $link->getKey(),
                'label' => $link->label,
                'url' => $link->url,
            ])->all(),
            /*
             * Both, and that is the definition of done: *"a property can be
             * linked to more than one deal over time and shows both."* A house
             * listed, withdrawn, and listed again next year is two rows here,
             * and the first one is the history that a `deals.property_id`
             * column would have overwritten.
             */
            'deals' => $property->dealLinks->map(fn (DealProperty $link): array => [
                'id' => $link->getKey(),
                'dealId' => $link->deal_id,
                'name' => $link->deal->displayName(),
                'state' => $link->deal->state->value,
                'sideLabel' => $link->deal->dealType->side->label(),
                'isSubject' => $link->is_subject,
            ])->all(),
            'propertyTypes' => PropertyType::options(),
            'propertyStatuses' => PropertyStatus::options(),
            'can' => [
                'update' => $request->user()?->can('update', $property) ?? false,
                'link' => $request->user()?->can('link', $property) ?? false,
            ],
        ]);
    }

    public function store(StorePropertyRequest $request, SaveProperty $save): RedirectResponse
    {
        $property = $save->create($request->safe()->except('links'), $request->links());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property added.')]);

        return to_route('properties.show', $property);
    }

    public function update(UpdatePropertyRequest $request, Property $property, SaveProperty $save): RedirectResponse
    {
        $save->update($property, $request->safe()->except('links'), $request->links());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property updated.')]);

        return to_route('properties.show', $property);
    }

    /**
     * Soft, and that is the whole retention story (PRD §9).
     *
     * A property is a business record rather than a lookup, so unlike deal
     * types (S76) it *does* get a destroy — nothing points at it that would be
     * orphaned by its absence, because `deal_properties` cascades and a deal
     * that no longer lists a house it never bought is correct. The 30-day
     * window is `records:purge`'s, and it discovers this table through
     * `team_id` like every other.
     */
    public function destroy(Property $property): RedirectResponse
    {
        $this->authorize('delete', $property);

        $property->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property deleted.')]);

        return to_route('properties.index');
    }
}
