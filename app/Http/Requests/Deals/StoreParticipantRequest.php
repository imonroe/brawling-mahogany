<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\ParticipantRole;
use App\Http\Requests\People\PersonRules;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S25 collects one of two shapes, and validates whichever arrived.
 *
 * **Search existing** sends a `team_membership_id`. **Create new** sends a
 * name and optional contact details, and the controller makes the directory
 * entry through `SavePerson` before adding it — issue #60: *"creating inline
 * must not require leaving the deal"*, and it must also not become a second,
 * lesser way of creating a person.
 */
class StoreParticipantRequest extends FormRequest
{
    /*
     * The directory rules, not a second set of them.
     *
     * The first version of this validated `email` as `nullable|string|email`
     * and nothing more, then handed it to `SavePerson` — straight into
     * `team_memberships`' partial unique index over `(team_id, lower(email))`.
     * A duplicate address was a 500, which is the exact defect this trait was
     * written to stop, arriving through a second code path. `/people` answered
     * the same input with a sentence.
     *
     * Using the trait also brings `prepareForValidation()`, so the address is
     * folded before the rule compares it — the rule and the `lower(email)`
     * index have to fold the same way, which is a lesson this repo has already
     * paid for twice.
     */
    use PersonRules;

    public function authorize(): bool
    {
        $deal = $this->deal();

        return $deal instanceof Deal
            && ($this->user()?->can('create', [DealParticipant::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:10000'],

            /*
             * Scoped rather than a bare `exists`. A foreign membership id in a
             * form body is the vector the isolation suite enumerates. The
             * composite foreign key would refuse it at the database anyway —
             * but a constraint violation is a 500, and this is a 422 that says
             * which field.
             */
            'team_membership_id' => [
                'required_without:first_name',
                'nullable',
                'string',
                Rule::exists('team_memberships', 'id')->where(
                    fn ($query) => $query
                        ->where('team_id', app(TeamContext::class)->requireId(TeamMembership::class))
                        ->whereNull('deleted_at')
                        ->whereNull('revoked_at'),
                ),
            ],

            /*
             * The create-inline half, held to `/people`'s rules rather than a
             * looser copy of them. `uniqueWithinTeam()` matches the partial
             * index predicate-for-predicate — team, `deleted_at IS NULL`,
             * `revoked_at IS NULL`, `lower(email)` — which is why it is reused
             * rather than re-derived.
             *
             * Only when a new person is actually being created: picking an
             * existing one sends no name, and the modal sends an empty `email`
             * either way.
             */
            'first_name' => ['required_without:team_membership_id', 'nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::when(
                    fn (): bool => is_string($this->input('first_name')) && trim($this->input('first_name')) !== '',
                    [$this->uniqueWithinTeam(null)],
                ),
            ],
            'phone' => ['nullable', 'string', 'max:50'],

            /*
             * The same pairing twice on one deal is what
             * `deal_participants_unique_role` refuses, and a constraint
             * violation is a 500. `whereNull('deleted_at')` because the index
             * is partial: removing somebody has to free the pairing again, and
             * a rule without it would refuse the re-add.
             *
             * `DealRoster::add()` catches the violation as well, because a
             * rule cannot close the window between asking and inserting.
             */
            'participant_role' => [
                'required',
                Rule::enum(ParticipantRole::class),
                Rule::unique('deal_participants', 'participant_role')->where(
                    fn ($query) => $query
                        ->where('deal_id', $this->deal()?->getKey())
                        ->where('team_membership_id', $this->input('team_membership_id'))
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /** The deal this request is against, from the route. */
    public function deal(): ?Deal
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal ? $deal : null;
    }

    /** The membership named by the request, when it named one. */
    public function membership(): ?TeamMembership
    {
        $id = $this->validated('team_membership_id');

        return is_string($id) && $id !== ''
            ? TeamMembership::query()->whereKey($id)->first()
            : null;
    }

    public function role(): ParticipantRole
    {
        return ParticipantRole::from((string) $this->validated('participant_role'));
    }
}
