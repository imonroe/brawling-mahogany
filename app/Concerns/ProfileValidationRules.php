<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Person;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Fold the address before anything compares it.
     *
     * `people.email` is stored lower-cased and its unique index is over
     * `lower(email)`, but `Rule::unique` compares verbatim — so somebody
     * retyping their own address with a capital passed validation and then hit
     * the index, which is a 500 whose Postgres `DETAIL` line carries the
     * address into the log (PRD §9: no PII in logs, ever).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function foldEmail(array $input): array
    {
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        return $input;
    }

    /**
     * The rules for a person's own profile fields.
     *
     * IA §10 formats a person as First Last and sorts by last, which is why
     * these are two fields rather than one. Only the given name is required:
     * a directory that refuses a one-name contact is a directory somebody
     * types "." into.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
     */
    protected function profileRules(int|string|null $personId = null): array
    {
        return [
            'first_name' => $this->nameRules(),
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => $this->emailRules($personId),
        ];
    }

    /**
     * The login half alone, for a context with no team to hold a name (#140).
     *
     * Registration is the only one: an account exists before any team knows
     * the person, so asking for a name there would be collecting something
     * with nowhere to go.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
     */
    protected function credentialRules(int|string|null $personId = null): array
    {
        return ['email' => $this->emailRules($personId)];
    }

    /**
     * Get the validation rules used to validate person names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate email addresses.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function emailRules(int|string|null $personId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            /*
             * Unique among the living only, matching the partial index on the
             * table. A plain unique rule counts soft-deleted rows, so somebody
             * who deleted their account could never register again and an
             * invitation to them would fail with "The email has already been
             * taken" (PRD §9's 30-day recovery window makes that a live case).
             */
            $personId === null
                ? Rule::unique(Person::class)->whereNull('deleted_at')
                : Rule::unique(Person::class)->whereNull('deleted_at')->ignore($personId),
        ];
    }
}
