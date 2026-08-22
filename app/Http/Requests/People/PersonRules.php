<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Enums\PersonLifecycleState;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
     * The rule that asks the question the index answers.
     *
     * Written by hand rather than with `Rule::unique`, and the reason is the
     * comparison. `Rule::unique('team_memberships', 'email')` emits
     * `where "email" = ?`, which Postgres evaluates case-sensitively, while
     * the index is over `lower(email)`. That rule matched the index only
     * because `TeamMembership::email()`'s mutator folds every Eloquent write —
     * so it was really matching the *mutator*, and a row written any other way
     * (the migration's own `UPDATE`, for one) put B2's 500 straight back.
     *
     * `DB::table` rather than the model, so the global team scope and the
     * soft-delete scope are both out of the picture and the three conditions
     * below are visibly the three in the index: same team, live row, live
     * membership.
     */
    private function uniqueWithinTeam(?TeamMembership $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            /*
             * `requireId()`, not `id()`, and the difference is which way this
             * fails with no team resolved.
             *
             * `where('team_id', null)` becomes `whereNull('team_id')` on a
             * NOT NULL column, so the rule would match nothing and *pass* —
             * granting permission where every neighbouring layer refuses.
             * `TeamScope`, `BelongsToTeam::creating` and the query below all
             * throw or fail closed; a validation rule that answers "no team"
             * with "go ahead" is the odd one out. Unreachable behind the
             * `team` middleware today, which is exactly when this is cheap.
             */
            $query = DB::table('team_memberships')
                ->where('team_id', app(TeamContext::class)->requireId(TeamMembership::class))
                ->whereNull('deleted_at')
                ->whereNull('revoked_at')
                ->whereRaw('lower(email) = ?', [mb_strtolower(trim($value))]);

            // The row being edited is not its own duplicate, or nobody could
            // ever change their own surname.
            if ($ignoring instanceof TeamMembership) {
                $query->where('id', '!=', $ignoring->getKey());
            }

            if ($query->exists()) {
                $fail('Somebody in your directory already has this email address.');
            }
        };
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
