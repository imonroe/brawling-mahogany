<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Put a property on a deal, from the deal side (S20 · issue #62).
 *
 * The mirror of `Properties\LinkDealRequest`, which does the same job from
 * S36. Two screens, one service (`PropertyDeals::link()`), so the rule about
 * what becomes the subject lives in one place rather than in whichever screen
 * was written first.
 */
class LinkPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal
            && ($this->user()?->can('create', [DealProperty::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $deal = $this->route('deal');

        return [
            /*
             * Scoped, not a bare `exists`. A foreign property id in a form
             * body is what the isolation suite enumerates, and the composite
             * foreign key would refuse it at the database — but a constraint
             * violation is a 500 and this is a 422 naming the field.
             */
            'property_id' => [
                'required', 'string',
                Rule::exists('properties', 'id')->where(
                    fn ($query) => $query
                        ->where('team_id', app(TeamContext::class)->requireId(Property::class))
                        ->whereNull('deleted_at'),
                ),

                /*
                 * The same pair twice is what `deal_properties_unique_pair`
                 * refuses. Partial on `deleted_at`, so removing a property
                 * frees the pair again — a house taken off by mistake has to
                 * be able to go back.
                 */
                Rule::unique('deal_properties', 'property_id')->where(
                    fn ($query) => $query
                        ->where('deal_id', $deal instanceof Deal ? $deal->getKey() : null)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'property_id.unique' => 'This property is already on this deal.',
        ];
    }
}
