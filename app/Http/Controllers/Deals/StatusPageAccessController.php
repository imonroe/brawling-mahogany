<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Mail\StatusPageLinkMail;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Person;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/**
 * The agent's half of the client status page (PRD §4.7 F7.1 · issue #110).
 *
 * A client's access is granted, handed over and taken away from the deal's
 * **People** tab, because that is where the roster is and the roster is what
 * this is about — *"can Dana see this deal"* is a fact about Dana's place on
 * it, not a setting.
 *
 * ## Three actions, and the middle one is ADR 0003's second door
 *
 * `send` mails the link. `handOver` returns the URL to the screen so the agent
 * can read it down the phone or paste it into a text — which is the whole of
 * ADR 0003's requirement, and the same shape S74 uses for an invitation. A
 * client who never receives the email is not a client who cannot see the page.
 *
 * `revoke` kills both credentials at once.
 *
 * ## Authorised on the deal, not on a permission of its own
 *
 * The catalogue has `status_page.view`, and it is the **client's** permission —
 * `PermissionSurface::Client`, held by the Status Viewer role. Granting
 * somebody access to a deal is deal work, so it asks `deals.manage`. Inventing
 * a `status_page.manage` would put a key in the catalogue that no shipped role
 * holds, which is the argument `TaskPolicy` makes at length.
 *
 * ## And the membership has to be a client **on this deal**
 *
 * The route is `deals/{deal}/people/{membership}/status-page` and the two bind
 * independently: the team scope proves both belong to the team and says
 * nothing about whether they belong to *each other*. Without `clientOn()`
 * below, a request naming any membership in the team — a colleague, or another
 * deal's seller — granted a full 14-day session to a deal that person has no
 * place on. The screen only draws the control on roster rows in a client role,
 * so this is reachable by a hand-crafted request only, which is exactly the
 * class of hole an authorization check exists for.
 */
class StatusPageAccessController extends Controller
{
    public function __construct(private readonly IssueStatusPageLink $links) {}

    public function send(
        Request $request,
        Deal $deal,
        TeamMembership $membership,
        TeamContext $teams,
    ): RedirectResponse {
        $this->authorize('update', $deal);

        $this->clientOn($deal, $membership);

        $address = $membership->email;

        if (! is_string($address) || trim($address) === '') {
            /*
             * A contact the team recorded without an address. Said plainly
             * rather than failing at the transport, where it would arrive as
             * a queue error nobody reads — and the hand-over below still
             * works, which is what the message points at.
             */
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('There is no email address for this person. Copy the link instead.'),
            ]);

            return back();
        }

        $issued = $this->links->issue($deal, $membership, $this->actor($request));

        $team = $teams->get();

        if ($team instanceof Team) {
            Mail::to($address)->send(new StatusPageLinkMail(
                team: $team,
                clientName: $membership->fullName(),
                url: $issued->url(),
                minutes: StatusPageLink::LINK_MINUTES,
                what: $deal->dealType?->side->clientLabel() ?? 'Your Transaction',
            ));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Link sent.')]);

        return back();
    }

    /**
     * The link, handed to the agent rather than to the client (ADR 0003).
     *
     * Flashed rather than rendered into the page's props, for the reason S74
     * flashes an invitation link: a credential that lives in a prop is a
     * credential in every subsequent partial reload of that screen.
     */
    public function handOver(Request $request, Deal $deal, TeamMembership $membership): RedirectResponse
    {
        $this->authorize('update', $deal);

        $this->clientOn($deal, $membership);

        $issued = $this->links->issue($deal, $membership, $this->actor($request));

        return back()->with('statusPageLink', [
            /*
             * Which deal it is for, so the People tab can refuse to draw it on
             * a different one. A flash survives one request and that request
             * is ordinarily the redirect back here — but *ordinarily* is not a
             * guarantee, and the panel's own copy says *"any link this person
             * already had for **this** deal"*, which would be a false sentence
             * on somebody else's roster.
             */
            'dealId' => (string) $deal->getKey(),
            'membershipId' => $membership->getKey(),
            'name' => $membership->fullName(),
            'url' => $issued->url(),
            'minutes' => StatusPageLink::LINK_MINUTES,
        ]);
    }

    public function revoke(Request $request, Deal $deal, TeamMembership $membership): RedirectResponse
    {
        $this->authorize('update', $deal);

        /*
         * Revoke is deliberately **not** narrowed the way the two grants are.
         * Taking access away must never be blocked by the participant's role
         * having been edited since it was granted — the failure mode of a
         * guard on a revoke path is somebody keeping access they should not
         * have.
         */
        $links = StatusPageLink::query()
            ->where('deal_id', $deal->getKey())
            ->where('team_membership_id', $membership->getKey())
            ->live()
            ->get();

        foreach ($links as $link) {
            $this->links->revoke($link, $this->actor($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status page access revoked.')]);

        return back();
    }

    /**
     * Refuse unless this membership is a client participant on this deal.
     *
     * The client roles, matching what S57's People tab actually offers the
     * control on: a seller or a buyer. A lender or an inspector is on the deal
     * and is not the person the status page was written for — IA §9's whole
     * surface is addressed to *the client whose transaction this is*, and the
     * reassurance paragraph reads as nonsense to anybody else.
     *
     * A 404 rather than a 403: the pairing is what does not exist.
     */
    private function clientOn(Deal $deal, TeamMembership $membership): void
    {
        abort_unless(
            DealParticipant::query()
                ->where('deal_id', $deal->getKey())
                ->where('team_membership_id', $membership->getKey())
                ->whereIn('participant_role', [
                    ParticipantRole::Seller->value,
                    ParticipantRole::Buyer->value,
                ])
                ->exists(),
            404,
        );
    }

    private function actor(Request $request): ?Person
    {
        $person = $request->user();

        return $person instanceof Person ? $person : null;
    }
}
