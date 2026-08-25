<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\OfferDirection;
use App\Enums\OfferStatus;
use App\Models\Deal;
use App\Models\DealProperty;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules both offer requests share (S22 · issue #73).
 *
 * `ResolvesTaskFields`'s sibling, and for the same reason: a store and an
 * update that spell the same field two ways drift the first time one of them
 * changes.
 */
trait OfferRules
{
    /**
     * @return array<string, mixed>
     */
    protected function offerRules(Deal $deal): array
    {
        return [
            'direction' => ['required', Rule::enum(OfferDirection::class)],
            'status' => ['required', Rule::enum(OfferStatus::class)],
            /*
             * Integer cents, never a float (ADR 0001). The form multiplies by
             * a hundred at the boundary, the way `PersonFormDialog` does for a
             * vendor's typical cost.
             */
            'amount' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'earnest_money' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'contingencies' => ['nullable', 'array'],
            'contingencies.*' => ['nullable', 'string', 'max:255'],
            'submitted_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            /*
             * Held to the properties **on this deal**, not merely to a
             * property that exists.
             *
             * The global scope answers "whose team" and the policy answers
             * "may this person"; two deals in the same team pass both while
             * being different transactions. Only the link answers "whose
             * deal", which is the same question `OverrideGateRequest` asks of
             * a gate.
             */
            'property_id' => ['nullable', 'string', $this->linkedToThisDeal($deal)],
        ];
    }

    private function linkedToThisDeal(Deal $deal): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($deal): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $linked = DealProperty::query()
                ->where('deal_id', $deal->getKey())
                ->where('property_id', $value)
                ->exists();

            if (! $linked) {
                $fail('That property is not on this deal. Link it on the Properties tab first.');
            }
        };
    }
}
