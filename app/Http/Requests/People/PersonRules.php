<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Enums\PersonLifecycleState;
use Illuminate\Validation\Rule;

/**
 * The fields S32 collects, in one place so create and edit cannot drift.
 *
 * The email is deliberately **not** unique-validated. A person shared across
 * teams already has a row, and the correct behaviour is to attach a membership
 * to it — the screen warns about the duplicate and offers the existing record
 * (S32), rather than the form refusing.
 */
trait PersonRules
{
    /**
     * Fold the address before anything compares or stores it, so the form,
     * the model, and the `lower(email)` index all agree.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function personRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(PersonLifecycleState::class)],
            'notes' => ['nullable', 'string', 'max:10000'],

            'is_vendor' => ['boolean'],
            'vendor_specialties' => ['array'],
            'vendor_specialties.*' => ['string', 'max:60'],
            // Integer cents, never a float (ADR 0001). A vendor's typical
            // charge is money like any other.
            'vendor_typical_cost' => ['nullable', 'integer', 'min:0'],
            'vendor_service_area' => ['nullable', 'string', 'max:255'],
            'vendor_rating' => ['nullable', 'integer', 'between:1,5'],
            'vendor_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
