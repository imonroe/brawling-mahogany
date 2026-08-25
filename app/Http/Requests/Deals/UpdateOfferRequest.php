<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;

/** Editing one (S22 · issue #73). */
class UpdateOfferRequest extends FormRequest
{
    use OfferRules;

    public function authorize(): bool
    {
        $deal = $this->route('deal');
        $person = $this->user();

        return $deal instanceof Deal && $person !== null && $person->can('update', $deal);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $deal = $this->route('deal');

        return $this->offerRules($deal instanceof Deal ? $deal : new Deal);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForOffer(): array
    {
        return $this->validated();
    }
}
