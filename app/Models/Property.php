<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasDocuments;
use App\Models\Concerns\HasExternalLinks;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    use BelongsToTeam, HasDocuments, HasExternalLinks, HasFactory, HasProductDefaults;

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
     * A parcel number is stored the way it is compared.
     *
     * `properties_team_parcel_unique` folds case and nothing else, and the
     * rule that guards it trims before it asks — so `"12-345 "` was invisible
     * to both and two live properties could sit on one parcel number. Over
     * HTTP `TrimStrings` hid it; the seeder, an import, and #62's screen do
     * not go through `TrimStrings`, which is the same argument
     * `SafeUrl::normalise()` answers for a URL and that this field was left
     * out of.
     *
     * On the model rather than in the action, so every writer inherits it —
     * including the factory, which is what makes a test able to reproduce the
     * shape a screen produces.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function parcelNumber(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => self::normaliseParcel($value));
    }

    /**
     * Look a parcel number up the way the index compares them.
     *
     * The mutator above governs what is **written**; a query's `where` is not
     * a write, so `firstOrCreate(['parcel_number' => '  zz  '])` asks for the
     * untrimmed string, misses the row it wrote a moment ago, and inserts a
     * second — straight into `properties_team_parcel_unique`. Anything that
     * looks a parcel number up by value should come through here.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereParcel(Builder $query, mixed $parcel): Builder
    {
        $parcel = self::normaliseParcel($parcel);

        return $parcel === null
            ? $query->whereNull('parcel_number')
            : $query->whereRaw('lower(parcel_number) = lower(?)', [$parcel]);
    }

    /** The one spelling of "the same parcel number", shared with the rule. */
    public static function normaliseParcel(mixed $value): ?string
    {
        $parcel = trim((string) (is_scalar($value) ? $value : ''));

        return $parcel === '' ? null : $parcel;
    }

    /**
     * This property's photographs (S38 · #63).
     *
     * A gallery image is a `document` with category `photo`, per PRD §7.14 —
     * *"`Photos` should be the general document table with a category"* — so
     * Slice 3's document module sits on this rather than beside it.
     *
     * Named `photos` because scoped route binding resolves a child through a
     * relation named for the parameter: `/properties/{property}/photos/{photo}`
     * looks for exactly this method.
     *
     * @return MorphMany<Document, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->orderBy('sort_order')
            ->orderBy('created_at');
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
