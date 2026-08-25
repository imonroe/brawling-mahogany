<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OfferDirection;
use App\Enums\OfferStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One offer on a deal (PRD §4.3 F3.6, §6.2, §7.9 · S22 · issue #73).
 *
 * The team's own working record of terms and dates. **Not the contract**: PRD
 * §10 leaves the executed document and its security obligation in CTM, and
 * §2.2 confirms e-signature is unnecessary in Emily's market. There is no file
 * on this row and there must not be one.
 *
 * `deal_id` and `property_id` are not fillable — a request body must not
 * choose which deal an offer lands on — and `team_id` never is, per
 * `BelongsToTeam`.
 *
 * @property string $deal_id
 * @property string|null $property_id
 * @property OfferDirection $direction
 * @property OfferStatus $status
 * @property int $amount
 * @property int|null $earnest_money
 * @property string|null $terms
 * @property array<string, mixed>|null $contingencies
 * @property Carbon|null $submitted_on
 * @property Carbon|null $expires_on
 */
#[Fillable([
    'direction', 'status', 'amount', 'earnest_money',
    'terms', 'contingencies', 'submitted_on', 'expires_on',
])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => OfferDirection::class,
            'status' => OfferStatus::class,
            'contingencies' => 'array',
            // Days, not instants (#165). An offer is submitted on a date.
            'submitted_on' => 'date',
            'expires_on' => 'date',
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

    /**
     * Still live — draft, submitted, or countered.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (OfferStatus $status): string => $status->value,
            array_filter(OfferStatus::cases(), fn (OfferStatus $status): bool => $status->isOpen()),
        ));
    }

    /**
     * Past its date and not yet resolved.
     *
     * **Derived, never stored.** The same rule `Task::state()` follows and for
     * the same reason: an expiry that has to be written by a nightly job is an
     * expiry that is wrong until the job runs, and a team looking at an offer
     * at 9am needs it to be right then. The team's calendar decides which day
     * it is, not the server's.
     */
    public function hasExpired(): bool
    {
        if (! $this->status->isOpen() || $this->expires_on === null) {
            return false;
        }

        return $this->expires_on->toDateString() < $this->today();
    }

    /**
     * What the team calls this offer in a sentence.
     *
     * Amounts are rendered by `lib/formatters.ts` per IA §10 — nothing here
     * spells money.
     */
    public function displayStatus(): OfferStatus
    {
        return $this->hasExpired() ? OfferStatus::Expired : $this->status;
    }

    /**
     * Today in the team's calendar, from the resolved context.
     *
     * `TeamContext` and not `$this->team`, which is the same choice
     * `Task::today()` makes and records: the context is already in memory,
     * and asking the relation would be a query per row on a list of offers.
     */
    private function today(): string
    {
        $team = app(TeamContext::class)->get();

        $timeZone = $team instanceof Team ? $team->timezone : config('app.timezone');

        return CarbonImmutable::now(is_string($timeZone) ? $timeZone : 'UTC')->toDateString();
    }
}
