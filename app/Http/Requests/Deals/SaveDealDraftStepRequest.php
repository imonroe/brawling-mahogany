<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\DealDraftStep;
use App\Models\Deal;
use App\Models\DealDraft;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One step of the create-deal wizard (S14 · issue #74).
 *
 * ## Why the rules are per step and not one set
 *
 * A wizard that validated everything on every submit could not have a step
 * two: the person has not answered step three yet, and telling them so is the
 * behaviour that makes multi-step forms hateful. So each step validates what
 * it owns and nothing else, and `RecordDealDraft::record()` merges only those
 * keys — which is also what makes Back safe.
 *
 * ## Inline creation is not here, and that is not an omission
 *
 * PRD §5.2 step 2 allows a client to be *"created inline"*, and a brand-new
 * listing is not in the directory either — so both steps need it. It lives in
 * `CreateDraftClientRequest` and `CreateDraftPropertyRequest`, one endpoint
 * each, because those reuse `PersonRules` (S32) and `PropertyRules` (S37) and
 * **both of those traits define `prepareForValidation()`**. One class cannot
 * use both without `insteadof` silently discarding one of them — which would
 * have thrown away either the email case-folding or the state-code
 * upper-casing, each of which exists because a partial index or a renderer
 * depends on it.
 *
 * Reusing them rather than restating them is the point: #60 paid for the
 * lesson that a second, looser copy of the directory's rules turns a duplicate
 * address into a 500.
 */
class SaveDealDraftStepRequest extends FormRequest
{
    use ResolvesDraftRole;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DealDraft::class) ?? false;
    }

    public function step(): DealDraftStep
    {
        return DealDraftStep::tryFrom((string) $this->input('step')) ?? DealDraftStep::Type;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $team = app(TeamContext::class)->requireId(Deal::class);

        $rules = ['step' => ['required', Rule::enum(DealDraftStep::class)]];

        return [...$rules, ...match ($this->step()) {
            DealDraftStep::Type => [
                /*
                 * Scoped *and* selectable. A bare `exists` would accept a
                 * system type that has been archived, and S76's archive
                 * dialog promises no new deal can use one — `Deal` refuses it
                 * at save time, and a 500 at the last step of a wizard is the
                 * worst place to discover that.
                 */
                'deal_type_id' => [
                    'required', 'string',
                    Rule::exists('deal_types', 'id')->where(
                        fn ($query) => $query
                            ->whereNull('archived_at')
                            ->whereNull('deleted_at')
                            ->where(fn ($inner) => $inner->whereNull('team_id')->orWhere('team_id', $team)),
                    ),
                ],
                // IA §10: a typed name always wins, and the wizard is the
                // first place somebody can type one.
                'name' => ['nullable', 'string', 'max:255'],
            ],

            DealDraftStep::Client => [
                'team_membership_id' => [
                    'required', 'string',
                    Rule::exists('team_memberships', 'id')->where(
                        fn ($query) => $query
                            ->where('team_id', app(TeamContext::class)->requireId(TeamMembership::class))
                            ->whereNull('deleted_at')
                            ->whereNull('revoked_at'),
                    ),
                ],

                /*
                 * The role, because a rental expects neither Seller nor Buyer
                 * and `DealRoster` invents nothing.
                 *
                 * **Required exactly where nothing implies one.** Nullable
                 * throughout was a silent data-loss bug: on a Rental or Other
                 * type `expectedRoles()` is empty, so a draft saved with no
                 * role reached the last button, `addClient()` found nothing to
                 * add, and the deal was created with the client quietly
                 * dropped and no error anywhere. The screen already asks on
                 * those types; this is the half that makes the answer count.
                 */
                'participant_role' => $this->participantRoleRules(),
            ],

            DealDraftStep::Property => [
                /*
                 * Nullable throughout: a buyer's deal is opened before there
                 * is a property to buy, which IA §13.4 calls the normal way
                 * round rather than an edge case.
                 */
                'property_id' => [
                    'nullable', 'string',
                    Rule::exists('properties', 'id')->where(
                        fn ($query) => $query
                            ->where('team_id', app(TeamContext::class)->requireId(Property::class))
                            ->whereNull('deleted_at'),
                    ),
                ],

            ],

            DealDraftStep::Template => [
                'workflow_template_id' => [
                    'nullable', 'string',
                    Rule::exists('workflow_templates', 'id')->where(
                        fn ($query) => $query
                            ->where('is_active', true)
                            ->whereNull('deleted_at')
                            ->where(fn ($inner) => $inner->whereNull('team_id')->orWhere('team_id', $team)),
                    ),
                ],
            ],
        }];
    }

    /**
     * Only the keys this step owns.
     *
     * The whole reason `RecordDealDraft::record()` takes an explicit array
     * rather than `validated()`: handing over everything would let a step
     * write keys it does not own, and a Back-then-save would erase later
     * answers.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return match ($this->step()) {
            DealDraftStep::Type => [
                'deal_type_id' => $this->validated('deal_type_id'),
                'name' => $this->validated('name'),
            ],
            DealDraftStep::Client => [
                'team_membership_id' => $this->validated('team_membership_id'),
                'participant_role' => $this->validated('participant_role'),
            ],
            DealDraftStep::Property => ['property_id' => $this->validated('property_id')],
            DealDraftStep::Template => ['workflow_template_id' => $this->validated('workflow_template_id')],
        };
    }
}
