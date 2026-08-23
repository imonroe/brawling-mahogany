<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealDraft;
use App\Models\DealType;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Properties\PropertyDeals;
use App\Support\Workflow\InstantiateWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turn a finished draft into the deal PRD §5.2 describes (S14 · issue #74).
 *
 * The five steps of that walkthrough, in one transaction:
 *
 * 1. a deal of the chosen type,
 * 2. the client added as a participant in the role the type implies,
 * 3. the subject property linked,
 * 4. the workflow attached, snapshotting the template,
 * 5. — which is not a step. Instantiating activates the first stage (#66),
 *    and its tasks are in the queue the moment the transaction commits.
 *
 * ## Nothing here is reimplemented
 *
 * Every one of those goes through the service that already owns it —
 * `DealRoster`, `PropertyDeals`, `InstantiateWorkflow`. That is not tidiness:
 * each of those carries rules this one would otherwise have to restate and get
 * subtly wrong. `DealRoster::add()` refreshes the derived name; `link()`
 * decides whether a first property becomes the subject, which differs by deal
 * side; `InstantiateWorkflow` refuses a template from another team. The
 * recurring defect in this slice has been a rule written into one caller that
 * the second caller was written without, and a wizard that wrote its own
 * inserts would be the fifth instance.
 *
 * ## One transaction, and why it has to be
 *
 * A deal with a client and no property is a half-made record somebody has to
 * repair by hand; a deal whose workflow failed to attach is worse, because it
 * looks finished. The wizard's promise is that pressing the last button either
 * produces the whole thing or changes nothing.
 */
final class CreateDealFromDraft
{
    public function __construct(
        private readonly DealRoster $roster,
        private readonly PropertyDeals $properties,
        private readonly InstantiateWorkflow $workflows,
    ) {}

    public function handle(DealDraft $draft): Deal
    {
        $type = $draft->dealType();

        if (! $type instanceof DealType) {
            /*
             * Step one is the only genuinely required answer, and it can go
             * stale: a type archived while the draft sat in a pocket is no
             * longer one a new deal may open on (S76's promise). A sentence on
             * the field, so the wizard sends them back to a step they can
             * answer.
             */
            throw ValidationException::withMessages([
                'deal_type_id' => 'Choose a deal type — the one this draft had is no longer available.',
            ]);
        }

        return DB::transaction(function () use ($draft, $type): Deal {
            $deal = new Deal;
            $deal->fill([
                'deal_type_id' => $type->getKey(),
                'name' => $draft->text('name'),
                'opened_at' => now(),
            ]);
            $deal->save();

            $this->addClient($draft, $deal);
            $this->addProperty($draft, $deal);
            $this->attachWorkflow($draft, $deal);

            $draft->forceFill([
                'deal_id' => $deal->getKey(),
                'completed_at' => now(),
            ])->save();

            return $deal;
        });
    }

    /**
     * The client, in the role the deal type implies.
     *
     * `DealRoster::expectedRoles()` decides Seller on a sale and Buyer on a
     * purchase — the same answer S19's "missing required role" warning uses,
     * so a deal the wizard produces never opens with that warning already
     * showing.
     *
     * **A rental expects neither, and nothing is invented for it.** That is
     * `DealRoster`'s own stance and there is no `Client` participant role to
     * fall back on: PRD §7.2 moved Client out of the role list precisely
     * because it describes a relationship rather than a part in a
     * transaction. So the wizard asks on those deal types, and the payload's
     * answer wins wherever it has one.
     */
    private function addClient(DealDraft $draft, Deal $deal): void
    {
        $id = $draft->text('team_membership_id');
        $role = $this->clientRole($draft, $deal);

        if ($id === null || ! $role instanceof ParticipantRole) {
            return;
        }

        $membership = TeamMembership::query()->whereKey($id)->first();

        if (! $membership instanceof TeamMembership) {
            // Deleted since the draft was started. The deal is still worth
            // making; S19 will say the role is missing, which is exactly what
            // that warning is for.
            return;
        }

        $this->roster->add(
            deal: $deal,
            membership: $membership,
            role: $role,
            isPrimary: true,
        );
    }

    /** What the client is on this deal: what they chose, or what the type implies. */
    private function clientRole(DealDraft $draft, Deal $deal): ?ParticipantRole
    {
        $chosen = $draft->text('participant_role');

        return ($chosen === null ? null : ParticipantRole::tryFrom($chosen))
            ?? DealRoster::expectedRoles($deal)[0]
            ?? null;
    }

    private function addProperty(DealDraft $draft, Deal $deal): void
    {
        $id = $draft->text('property_id');

        if ($id === null) {
            return;
        }

        $property = Property::query()->whereKey($id)->first();

        if ($property instanceof Property) {
            $this->properties->link($property, $deal);
        }
    }

    /**
     * Attach the workflow, if one was chosen.
     *
     * **Optional, deliberately.** F4.7 allows several workflows per deal
     * attached at different times — issue #74's own example is the *Under
     * Contract* workflow arriving weeks later — and S28 exists to add one to a
     * live deal. So a deal opened before a pack is installed, or by somebody
     * who does not yet know which process applies, is still a deal. The five
     * steps of §5.2 remain the happy path and the tests walk it end to end.
     */
    private function attachWorkflow(DealDraft $draft, Deal $deal): void
    {
        $id = $draft->text('workflow_template_id');

        if ($id === null) {
            return;
        }

        $template = WorkflowTemplate::query()->whereKey($id)->first();

        if ($template instanceof WorkflowTemplate) {
            // Refuses another team's private template itself (#66), so the
            // wizard does not have to and cannot forget to.
            $this->workflows->handle($deal, $template);
        }
    }
}
