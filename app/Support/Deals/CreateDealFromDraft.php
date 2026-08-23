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
     *
     * ## A chosen client is never quietly dropped
     *
     * Both client endpoints now require the role where nothing implies one, so
     * a draft carrying a membership and no role should not exist. This refuses
     * rather than returning, because the version that returned is exactly how
     * the bug read from the outside: a Rental deal, created successfully, with
     * the client the person had picked simply absent and nothing said. A
     * refusal sends them back to a step they can answer; silence does not.
     */
    private function addClient(DealDraft $draft, Deal $deal): void
    {
        $id = $draft->text('team_membership_id');

        if ($id === null) {
            return;
        }

        $role = $this->clientRole($draft, $deal);

        if (! $role instanceof ParticipantRole) {
            throw ValidationException::withMessages([
                'participant_role' => "Choose their part in this deal — this deal type doesn't imply one.",
            ]);
        }

        $membership = TeamMembership::query()
            ->whereKey($id)
            /*
             * Revoked since the draft was started. Both the step's `exists`
             * rule and S25's own picker refuse a revoked membership; this is
             * the third place that has to, because a draft can sit between
             * them for a month. Without it the wizard was the one way to put
             * somebody back on a deal after their access was taken away.
             */
            ->whereNull('revoked_at')
            ->first();

        if (! $membership instanceof TeamMembership) {
            /*
             * Revoked or deleted since the draft was started.
             *
             * This used to return, on the argument that "the deal is still
             * worth making; S19 will say the role is missing". **S19 says no
             * such thing on a Rental or an Other deal type**, because its
             * warning filters `expectedRoles()`, which is empty on both — so
             * the argument held on two of the four sides and the comment
             * asserted a protection the code did not provide.
             *
             * The printout was blocker 2's, one branch over: a successful
             * create, a "Deal created." toast, zero participants, no warning,
             * and "Untitled deal". Refusing on all four sides is the same
             * answer as eleven lines up and needs no per-side reasoning to
             * stay correct.
             */
            throw ValidationException::withMessages([
                'team_membership_id' => 'Choose the client again — the person this draft had is no longer on your team.',
            ]);
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

        /*
         * Active, like both other callers check. A template deactivated
         * between step four and the last button is one the team has said to
         * stop starting work from, and instantiating snapshots the whole tree
         * — so attaching it here is the expensive half of the mistake. The
         * step's own `exists` rule checks `is_active`; the draft outlives the
         * step.
         */
        $template = WorkflowTemplate::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        if (! $template instanceof WorkflowTemplate) {
            /*
             * Refused rather than skipped, because this method's own class
             * docblock promises the last button "either produces the whole
             * thing or changes nothing" — and a deal created without the
             * workflow the person picked is the case that docblock calls
             * *worse* than a missing property, "because it looks finished".
             *
             * Not attaching at all stays legal: `attachWorkflow()` returns
             * early when nothing was chosen, and S28 exists to add one later.
             * This is only about a template that *was* chosen and has since
             * been withdrawn.
             */
            throw ValidationException::withMessages([
                'workflow_template_id' => 'Choose a workflow again, or skip it — the template this draft had is no longer active.',
            ]);
        }

        // Refuses another team's private template itself (#66), so the
        // wizard does not have to and cannot forget to.
        $this->workflows->handle($deal, $template);
    }
}
