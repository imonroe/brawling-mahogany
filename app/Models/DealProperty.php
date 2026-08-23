<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PropertyInterest;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DealPropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One property, on one deal (PRD §4.3 F3.4 · S36 · issue #61).
 *
 * A house listed, withdrawn, and listed again next year is two deals and one
 * property; nine houses toured by one buyer are one deal and nine properties.
 * Neither fits a column on either table, which is why this row exists.
 *
 * `is_subject` answers "which of the nine", because IA §10 derives a deal's
 * name from the subject property's street address and a deal with nine
 * properties and no subject cannot be named. It is also PRD §6.2's drafted
 * `link_role` narrowed: every link that is not the subject is a candidate, so
 * the pair is a boolean, and a boolean is what
 * `deal_properties_one_subject` can enforce without an application check.
 *
 * `interest_status` (#62) is the buyer's opinion of a **candidate**, and it is
 * nullable with no default because a seller-side deal's subject has no buyer
 * opinion to record — null means nobody has said, which is a different fact
 * from "Interested".
 *
 * **`is_subject` is not fillable.** Which property names the deal is decided
 * by `PropertyDeals`, in a transaction that demotes the incumbent; a request
 * body choosing it would meet the partial unique index instead.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string $property_id
 * @property bool $is_subject
 * @property PropertyInterest|null $interest_status
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['interest_status', 'sort_order'])]
class DealProperty extends Model
{
    /** @use HasFactory<DealPropertyFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_subject' => 'boolean',
            'interest_status' => PropertyInterest::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * A candidate is any link that is not the subject (PRD §6.2's
     * `link_role`, read the way #61 narrowed it).
     */
    public function isCandidate(): bool
    {
        return ! $this->is_subject;
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
