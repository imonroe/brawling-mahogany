<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasExternalLinks;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A property (PRD §4.3 F3.4, §6.2 · S35, S36, S37 · issue #61).
 *
 * **Team-owned and reusable across deals.** A house listed, withdrawn, and
 * listed again a year later is one property and two deals; nine houses toured
 * by one buyer are nine properties and one deal. `deal_properties` carries
 * both directions.
 *
 * ## The one thing this model must never grow
 *
 * PRD §10: MLS listing data is licensed, and *"v1 stores links only, never
 * ingested listing content."* Everything here is a fact a team typed about a
 * house — an address, a parcel number, a bed count. Nothing is a copy of a
 * listing, and a column that held one would be the licensing problem rather
 * than a convenience. The links live in `external_links` for exactly that
 * reason.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $street
 * @property string|null $unit
 * @property string|null $city
 * @property string|null $state_code
 * @property string|null $postal_code
 * @property string|null $parcel_number
 * @property PropertyType $type
 * @property PropertyStatus $status
 * @property int|null $beds
 * @property string|null $baths
 * @property int|null $sqft
 * @property int|null $year_built
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int|null $deal_links_count
 */
#[Fillable([
    'street', 'unit', 'city', 'state_code', 'postal_code',
    'parcel_number', 'type', 'status',
    'beds', 'baths', 'sqft', 'year_built', 'notes',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToTeam, HasExternalLinks, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'status' => PropertyStatus::class,
            'beds' => 'integer',
            'sqft' => 'integer',
            'year_built' => 'integer',
        ];
    }

    /**
     * The link rows themselves, which is what gets created and removed.
     *
     * @return HasMany<DealProperty, $this>
     */
    public function dealLinks(): HasMany
    {
        // Ordered like `Deal::propertyLinks()`, so a property's list of deals
        // is stable rather than whatever Postgres returns. S36 renders these
        // straight through, and a positional assertion against an unordered
        // relation is a test that passes by luck.
        return $this->hasMany(DealProperty::class)
            ->orderByDesc('is_subject')
            ->orderBy('created_at');
    }

    /**
     * What to call this property on a screen.
     *
     * IA §10 puts the street address first, and a property with no address at
     * all is still a real row — somebody creates one from a parcel number
     * before they know the street. Falling back to the parcel number beats
     * falling back to nothing, because an untitled row in a directory of
     * forty is unfindable.
     */
    public function displayName(): string
    {
        $street = trim((string) $this->street);

        if ($street !== '') {
            $unit = trim((string) $this->unit);

            return $unit === '' ? $street : $street.' '.$unit;
        }

        $parcel = trim((string) $this->parcel_number);

        return $parcel === '' ? 'Untitled property' : 'Parcel '.$parcel;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, PropertyStatus $status): Builder
    {
        return $query->where('status', $status);
    }
}
