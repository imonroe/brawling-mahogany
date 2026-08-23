<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\DealSide;
use App\Enums\PropertyInterest;
use App\Models\DealProperty;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the buyer thinks of a candidate (F3.5 · S20 · issue #62).
 */
class UpdateDealPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $link = $this->route('propertyLink');

        return $link instanceof DealProperty
            && ($this->user()?->can('update', $link) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Nullable is a real value here — it means *nobody has said*,
             * which is different from "Interested" and is what every row starts
             * as. `sometimes` keeps an absent key from being read as one.
             */
            'interest_status' => ['sometimes', 'nullable', Rule::enum(PropertyInterest::class)],
        ];
    }

    /**
     * Interest is buyer-side, and PRD F3.5 says so in one line: *"Buyer-side:
     * per-property interest status."*
     *
     * Refused rather than accepted-and-hidden. A column that fills up with
     * values no screen renders is the dead-data shape this codebase keeps
     * finding — and the sentence tells somebody why their sale deal has no
     * interest control rather than leaving them to guess.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('interest_status') || $this->input('interest_status') === null) {
                return;
            }

            $link = $this->route('propertyLink');

            if (! $link instanceof DealProperty) {
                return;
            }

            $link->loadMissing('deal.dealType');

            if ($link->deal?->dealType->side !== DealSide::Buy) {
                $validator->errors()->add(
                    'interest_status',
                    'Interest is something a buyer has. This is not a buy-side deal.',
                );
            }
        });
    }

    /**
     * The keys that arrived, by presence rather than by value.
     *
     * `interest_status: null` is an instruction — nobody has said — and an
     * absent key means leave it alone. `ConvertEmptyStringsToNull` erases that
     * distinction for every scalar in a request body, so presence is the only
     * thing left that can carry it.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->has('interest_status')
            ? ['interest_status' => $this->validated('interest_status')]
            : [];
    }
}
