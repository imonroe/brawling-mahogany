<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\ParticipantRole;
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
    public function authorize(): bool
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal
            && ($this->user()?->can('create', [DealParticipant::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'participant_role' => ['required', Rule::enum(ParticipantRole::class)],
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

            // The create-inline half. `SavePerson` owns the directory rules;
            // these are only what it takes to make a row.
            'first_name' => ['required_without:team_membership_id', 'nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
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
