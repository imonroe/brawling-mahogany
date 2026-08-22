<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Support\Links\SafeUrl;
use App\Support\Tenancy\TeamContext;
use Illuminate\Validation\Rule;

/**
 * The rules S37 collects, shared by create and edit (issue #61).
 *
 * One trait rather than two copies, for the reason `DealTypeRules` exists: the
 * create and edit forms are the same form, and two rule sets drift within a
 * month — usually in the direction of the edit path being looser.
 */
trait PropertyRules
{
    /**
     * @return array<string, mixed>
     */
    protected function propertyRules(?Property $property = null): array
    {
        return [
            /*
             * Every address part is optional, and that is not laziness.
             * A property is created from a parcel number before the street is
             * known, and from a street before the ZIP is looked up. Requiring
             * a full address would mean somebody typing a placeholder, and a
             * placeholder in an address field is worse than an empty one.
             */
            'street' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:255'],

            // Two letters, because the column is two characters and a
            // silently truncated state is a wrong address.
            'state_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'postal_code' => ['nullable', 'string', 'max:16'],

            /*
             * Unique per team, matching `properties_team_parcel_unique`
             * predicate for predicate: the team, `deleted_at IS NULL`, and
             * `lower(parcel_number)`.
             *
             * The rule exists because the index does. Shipping a partial
             * unique index with no matching rule has happened three times in
             * this repository (#143, #145, #148), and each time the symptom
             * was a 500 where a sentence belonged.
             */
            'parcel_number' => [
                'nullable', 'string', 'max:64',
                Rule::unique('properties', 'parcel_number')
                    ->where(fn ($query) => $query
                        ->where('team_id', app(TeamContext::class)->requireId(Property::class))
                        ->whereNull('deleted_at'))
                    ->ignore($property?->getKey())
                    ->withoutTrashed(),
            ],

            'type' => ['required', Rule::enum(PropertyType::class)],
            'status' => ['required', Rule::enum(PropertyStatus::class)],

            // Bounded because the columns are. `unsignedSmallInteger` tops out
            // at 65535, and a database-level range error is a 500.
            'beds' => ['nullable', 'integer', 'min:0', 'max:99'],
            'baths' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'sqft' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'year_built' => ['nullable', 'integer', 'min:1600', 'max:'.(date('Y') + 5)],

            'notes' => ['nullable', 'string', 'max:10000'],

            ...$this->linkRules(),
        ];
    }

    /**
     * The external links S37 edits inline (PRD §7.13).
     *
     * `present` rather than `required` on the array: an empty list is a
     * meaningful instruction — remove them all — and `required` would refuse
     * exactly that. A request that omits `links` entirely leaves them alone,
     * which `UpdatePropertyRequest` reads by presence.
     *
     * @return array<string, mixed>
     */
    private function linkRules(): array
    {
        return [
            'links' => ['sometimes', 'array', 'max:50'],
            'links.*.id' => ['nullable', 'string'],
            'links.*.label' => ['required', 'string', 'max:255'],

            /*
             * The scheme allowlist, not a `url` rule.
             *
             * `url` asks whether a string parses. `javascript:alert(1)` parses
             * and is script execution in the reader's session once it is an
             * `href`; Laravel's `url` rule accepts it. `SafeUrl` carries the
             * allowlist and `ExternalLink` refuses the same values on save,
             * because the next writer is #62's screen and not this request.
             */
            'links.*.url' => [
                'required', 'string', 'max:2048',
                fn (string $attribute, mixed $value, callable $fail) => SafeUrl::permits(
                    is_string($value) ? $value : null,
                ) ? null : $fail(SafeUrl::message()),
            ],
        ];
    }
}
