<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Enums\PersonLifecycleState;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The fields S32 collects, in one place so create and edit cannot drift.
 *
 * ## The address is unique **within this team**, and validated as such
 *
 * It used to be deliberately not unique-validated, on the grounds that a
 * shared `people` row was the right thing to attach to. #140 removed the
 * sharing and added a partial unique index over `(team_id, lower(email))` —
 * and left the rule behind, so a duplicate address became a 500 whose
 * Postgres DETAIL line carried the address into the log. PRD §9 forbids that
 * outright.
 *
 * S32's *"duplicate email produces a warning and an offer to open the existing
 * record, not a hard failure"* is still honoured, and by the same mechanism as
 * before: `/people/lookup` warns while somebody is typing. The rule here is
 * what stops the submit that follows it from being a 500.
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
     * The rule that matches the index, including its `where` clauses.
     *
     * The index is partial — live rows only — so the rule has to be too, or
     * an address freed by revoking somebody would still be refused.
     */
    private function uniqueWithinTeam(?TeamMembership $ignoring): Unique
    {
        $rule = Rule::unique('team_memberships', 'email')
            ->where(fn ($query) => $query
                ->where('team_id', app(TeamContext::class)->id())
                ->whereNull('deleted_at')
                ->whereNull('revoked_at'));

        return $ignoring instanceof TeamMembership ? $rule->ignore($ignoring->getKey()) : $rule;
    }

    /**
     * @return array<string, mixed>
     */
    protected function personRules(?TeamMembership $ignoring = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                $this->uniqueWithinTeam($ignoring),
            ],
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
