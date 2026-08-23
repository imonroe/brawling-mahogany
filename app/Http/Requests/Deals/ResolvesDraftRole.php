<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\ParticipantRole;
use App\Models\DealType;
use App\Models\Person;
use App\Support\Deals\DealRoster;
use App\Support\Deals\RecordDealDraft;
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
     * **And not where there is no usable deal type at all.** `dealType()`
     * returns null for a type archived while the draft sat, which makes
     * `impliedRole()` null for a reason that has nothing to do with roles — so
     * the unqualified version demanded a role and explained it with "this deal
     * type doesn't imply one", which is both wrong and unactionable. Step one
     * is the answer there, and `CreateDealFromDraft` says so at the button.
     *
     * @return array<int, mixed>
     */
    protected function participantRoleRules(): array
    {
        return [
            Rule::requiredIf(fn (): bool => $this->dealTypeIsUsable() && $this->impliedRole() === null),
            'nullable',
            Rule::enum(ParticipantRole::class),
        ];
    }

    /** Whether step one's answer still resolves to a type a deal may open on. */
    protected function dealTypeIsUsable(): bool
    {
        return $this->draftDealType() instanceof DealType;
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
        return DealRoster::impliedRole($this->draftDealType());
    }

    /** The deal type step one chose, if it is still selectable. */
    protected function draftDealType(): ?DealType
    {
        /** @var Person|null $person */
        $person = $this->user();

        // Through the service that owns the question, rather than a second
        // copy of its query. The first version wrote its own and dropped the
        // `latest('updated_at')` ordering — harmless while the partial unique
        // index holds, and exactly the two-places-that-can-drift shape this
        // slice keeps being reviewed for.
        $draft = $person === null ? null : app(RecordDealDraft::class)->existing($person);

        return $draft?->dealType();
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
