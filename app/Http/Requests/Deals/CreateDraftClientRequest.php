<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Http\Requests\People\PersonRules;
use App\Models\DealDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A client created inline, from step two of the wizard (PRD §5.2 · #74).
 *
 * Its own request rather than a branch of `SaveDealDraftStepRequest`, because
 * that class would then have to `use` both `PersonRules` and `PropertyRules`
 * and **both define `prepareForValidation()`** — a conflict PHP only resolves
 * by discarding one, which would silently drop either the email case-folding
 * or the state-code upper-casing. Two endpoints is the cheaper answer.
 *
 * The rules are `/people`'s own, unchanged. #60's finding, verbatim: a second,
 * looser copy validated `email` as `nullable|string|email` and handed it
 * straight to the partial unique index, where a duplicate address was a 500.
 */
class CreateDraftClientRequest extends FormRequest
{
    use PersonRules;
    use ResolvesDraftRole;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DealDraft::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', $this->uniqueWithinTeam(null)],
            'phone' => ['nullable', 'string', 'max:50'],

            /*
             * The same question the picker endpoint asks, through the same
             * trait. Creating the client inline is a different way to answer
             * step two, not a different step two — and this endpoint used to
             * accept no role at all, which on a Rental or Other deal type made
             * a participant impossible and dropped the client without a word.
             */
            'participant_role' => $this->participantRoleRules(),
        ];
    }
}
