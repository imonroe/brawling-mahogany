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
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Queries\PropertyDirectory;
use App\Support\Activity\RecordActivity;
use App\Support\Properties\PropertyDeals;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        /*
         * `whereHas('deal')` is not decoration.
         *
         * `DealProperty::deal()` is a plain `belongsTo`, so a soft-deleted
         * deal makes the relation null and this screen's mapping — which asks
         * every link for its deal's name and side — throws. There is no deal
         * destroy route yet; #74 brings one, and a screen that 500s the day a
         * neighbouring feature lands is a screen written to be broken later.
         */
        $property->load([
            'externalLinks',
            // One query for the gallery, not one per photo (#63).
            'photos',
            'dealLinks' => fn (HasMany $links) => $links->whereHas('deal'),
            'dealLinks.deal.dealType',
        ]);

        /*
         * Counted, not taken from the loaded collection.
         *
         * `dealLinks` above is filtered to links whose deal still exists, and
         * `destroy()` removes **every** link — so a count read off that
         * collection would understate what the delete confirmation is about to
         * do whenever a trashed deal is in the mix.
         */
        $property->loadCount('dealLinks');

        return Inertia::render('Properties/Show', [
            'property' => [
                ...PropertyDirectory::row($property),
                'parcelNumber' => $property->parcel_number,
                'yearBuilt' => $property->year_built,
                'notes' => $property->notes,
                'statusLabel' => $property->status->label(),
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
            /*
             * S38's gallery (#63). Every `url` is the download **route** and
             * never a bucket address: F6.4's *"no public buckets, every
             * download authorized and short-lived"* is a property of there
             * being exactly one way to read a file, and PRD §9 needs that way
             * to be auditable.
             */
            'photos' => $property->photos->map(fn (Document $photo): array => [
                'id' => $photo->getKey(),
                'url' => route('properties.photos.show', [$property, $photo]),
                'originalName' => $photo->original_name,
                'caption' => $photo->caption,
                'isPrimary' => $photo->is_primary,
            ])->values()->all(),
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
     * Soft, and the links have to go with it by hand (PRD §9).
     *
     * A property is a business record rather than a lookup, so unlike deal
     * types (S76) it *does* get a destroy. What it does not get is a cascade:
     * `teamScopedForeign()`'s `cascadeOnDelete()` is a **hard**-delete
     * cascade, and a soft delete never fires it. The first version of this
     * method said otherwise, and the consequence was worse than a dangling
     * row — `deal_properties_one_subject` is partial on
     * `is_subject AND deleted_at IS NULL`, so the surviving link kept the
     * subject slot and the deal could not acquire a replacement. IA §10's
     * generated name stayed pinned to a property nobody could see, for the
     * thirty days until `records:purge` force-deleted it and the cascade
     * finally ran.
     *
     * So the links are removed first, through `PropertyDeals` — the same path
     * the screen uses, so the timeline says what happened and #62's deal side
     * inherits the behaviour rather than reimplementing it. One transaction,
     * because a property deleted with half its links still attached is a
     * record somebody repairs by hand.
     */
    public function destroy(Property $property, PropertyDeals $deals, RecordActivity $activity): RedirectResponse
    {
        $this->authorize('delete', $property);

        DB::transaction(function () use ($property, $deals, $activity): void {
            // `deal.dealType`, because `NameDeal` asks the deal for its
            // side — without it that is one `deal_types` select per link.
            $property->dealLinks()->with('deal.dealType', 'property')->get()
                ->each(fn (DealProperty $link) => $deals->unlink($link));

            /*
             * Recorded before the delete, and on the property's own timeline.
             *
             * Adding one writes `property.added` and changing its status
             * writes `property.status_changed`; removing it wrote only the
             * unlink entries, which live on the *deals*. So a property with no
             * deals left the directory with no trace anywhere of who removed
             * it or when — the one event in its life that somebody comes
             * looking for.
             *
             * The external links go with it too, through
             * `HasExternalLinks`' own `deleting` hook: polymorphic, so no
             * foreign key reaches them and `records:purge` finds a row by its
             * `deleted_at` or not at all.
             */
            $activity->record(
                subject: $property,
                eventType: 'property.deleted',
                summary: 'Deleted '.$property->displayName(),
            );

            $property->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property deleted.')]);

        return to_route('properties.index');
    }
}
