<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Models\Deal;
use App\Models\Property;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Put a property on a deal, from the property side (S36 · issue #61).
 */
class LinkDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = $this->route('property');

        return $property instanceof Property
            && ($this->user()?->can('link', $property) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            /*
             * Scoped, not a bare `exists`. A foreign deal id in a form body is
             * what the isolation suite enumerates, and the composite foreign
             * key would refuse it at the database — but a constraint violation
             * is a 500 and this is a 422 that names the field.
             */
            'deal_id' => [
                'required', 'string',
                Rule::exists('deals', 'id')->where(
                    fn ($query) => $query
                        ->where('team_id', app(TeamContext::class)->requireId(Deal::class))
                        ->whereNull('deleted_at'),
                ),

                /*
                 * The same pair twice is what `deal_properties_unique_pair`
                 * refuses. `whereNull('deleted_at')` because that index is
                 * partial: unlinking has to free the pair again, or a property
                 * removed by mistake could never be put back.
                 *
                 * `PropertyDeals::link()` catches the violation as well — a
                 * rule cannot close the window between asking and inserting.
                 */
                Rule::unique('deal_properties', 'deal_id')->where(
                    fn ($query) => $query
                        ->where('property_id', $property instanceof Property ? $property->getKey() : null)
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
            'deal_id.unique' => 'This property is already on that deal.',
        ];
    }
}
