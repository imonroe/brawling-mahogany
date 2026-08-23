<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\ParticipantRole;
use App\Models\DealDraft;
use App\Models\Person;
use App\Support\Deals\DealRoster;
use Illuminate\Validation\Rule;

/**
 * The client's role, asked the same way by both wizard client endpoints.
 *
 * Two endpoints put a client on a draft — pick an existing one
 * (`SaveDealDraftStepRequest`) and create one inline
 * (`CreateDraftClientRequest`) — and PRD §7.2's rule applies identically to
 * both: a Sale implies Seller, a Purchase implies Buyer, and a Rental or Other
 * implies nothing, so on those the person has to say.
 *
 * It is a trait because the second endpoint was written without the rule. That
 * is the recurring defect of this slice — a rule written into one caller that
 * the second was written without — and the inline path was worse than the
 * picker path it copied: it never accepted `participant_role` at all, so on a
 * rental it *could not* produce a participant, and the deal was created with
 * the client silently dropped.
 */
trait ResolvesDraftRole
{
    /**
     * Required exactly where the deal type implies nothing.
     *
     * @return array<int, mixed>
     */
    protected function participantRoleRules(): array
    {
        return [
            Rule::requiredIf(fn (): bool => $this->impliedRole() === null),
            'nullable',
            Rule::enum(ParticipantRole::class),
        ];
    }

    /**
     * What this draft's deal type implies for the client, if anything.
     *
     * Read from the draft rather than the request body: step one chose the
     * type and this is a later submit, so the type is not in this body. The
     * draft is resolved from the actor for the same reason the wizard does —
     * there is no draft id in any URL.
     */
    protected function impliedRole(): ?ParticipantRole
    {
        /** @var Person|null $person */
        $person = $this->user();

        $draft = $person === null
            ? null
            : DealDraft::query()->open()->where('created_by_person_id', $person->getKey())->first();

        return DealRoster::impliedRole($draft?->dealType());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'participant_role.required' => "Choose their part in this deal — this deal type doesn't imply one.",
        ];
    }
}
