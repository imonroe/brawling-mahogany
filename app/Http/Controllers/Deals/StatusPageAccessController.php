<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Mail\StatusPageLinkMail;
use App\Models\Deal;
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

        $issued = $this->links->issue($deal, $membership, $this->actor($request));

        return back()->with('statusPageLink', [
            'membershipId' => $membership->getKey(),
            'name' => $membership->fullName(),
            'url' => $issued->url(),
            'minutes' => StatusPageLink::LINK_MINUTES,
        ]);
    }

    public function revoke(Request $request, Deal $deal, TeamMembership $membership): RedirectResponse
    {
        $this->authorize('update', $deal);

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

    private function actor(Request $request): ?Person
    {
        $person = $request->user();

        return $person instanceof Person ? $person : null;
    }
}
