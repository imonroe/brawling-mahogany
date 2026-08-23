<?php

declare(strict_types=1);

namespace App\Models;

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
 * properties and no subject cannot be named. #62 adds the interest vocabulary
 * and the deal-side screen that promotes between them; all this issue does is
 * set the flag on a deal's first property.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string $property_id
 * @property bool $is_subject
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['is_subject'])]
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
        ];
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
