<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPage;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Support\StatusPage\ClientStatus;
use App\Support\StatusPage\DispatchStatusPageLink;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S61, S62 and S64 — the client's own page (PRD §4.7 · IA §9 · #110, #111).
 *
 * ## One route, two credentials
 *
 * IA §6 fixes the client route as `/s/{token}` — *"short and opaque"* — and
 * #110 splits the credential in two: a 30-minute single-use link, and the
 * session it establishes. Both arrive here, and which one a token is decides
 * what happens:
 *
 *  1. A **live link** is spent, a session is minted, and the client is
 *     redirected to the session's own URL. That redirect is S61's *"success"*
 *     state, and it is what makes the address in their browser one that still
 *     works next week.
 *  2. A **live session** renders S62.
 *  3. Anything else is S64, which says *which* — expired, already used, or
 *     revoked are three different sentences to a client.
 *
 * ## Why the session token is in the URL rather than in a cookie
 *
 * A real trade, decided rather than inherited (#110 asks for exactly that).
 *
 * A cookie would be strictly stronger against referrer leakage and browser
 * history. What it would break is the thing this surface is *for*: a joint
 * sale where one client forwards the link to the other, and a client who opens
 * it on their phone and again on a laptop. PRD §3.3 — *"must work on a phone,
 * first try, no password"* — is about removing every step, and a link that
 * only works in the browser that first opened it puts one back.
 *
 * What makes it acceptable: the token is 256 bits of `random_bytes` and names
 * nothing (F7.7), the grant is revocable and an agent has the control, it
 * expires in a fortnight, and `ClientSurfaceHeaders` puts `Referrer-Policy:
 * no-referrer` and `X-Robots-Tag: noindex` on every one of these responses so
 * the URL does not leak sideways or into a search index. The same shape #108
 * accepts for an iCal feed, for the same reason.
 *
 * ## No `team` middleware, and no `auth`
 *
 * The token is what establishes the tenant — ADR 0002's stated exception, the
 * one an invitation already makes. A client has no membership to resolve and
 * no session to be in.
 *
 * **Establishing it is a thing this controller has to do**, not a thing the
 * sentence above makes true. The two token lookups lift the scope, and
 * everything after them — spending the link, touching the session, reading
 * the deal off the link, and every query `ClientStatus` composes — is an
 * ordinary scoped read that throws `MissingTeamContextException` with nothing
 * resolved. Shipped that way once: every one of these routes was a 500 for a
 * real client and green in the suite, because `TestCase::withTeam()` binds a
 * context into the container before the request and `auth()->logout()` does
 * not clear it. That is issue #156's trap, one surface along, and
 * `tests/Isolation/ClientSurfaceTenancyTest.php` is the answer to it: it
 * clears the binding and proves, with a control, that it is cleared.
 *
 * So `withinTeamOf()` wraps the work. Not `withoutTeamScope()` on each query —
 * that would answer *"whose data is this"* by not asking, on the one surface a
 * stranger reaches. Running inside the link's own team keeps every scope on
 * and pointed at the right tenant.
 */
class StatusPageController extends Controller
{
    /**
     * How many links one address may ask for, and over how long.
     *
     * Three in an hour. Enough for a client whose first two emails went to
     * spam, and short of a way to use this product as a mail cannon pointed at
     * somebody else's inbox.
     */
    private const REQUESTS_PER_ADDRESS = 3;

    private const REQUEST_WINDOW_SECONDS = 3600;

    public function __construct(private readonly IssueStatusPageLink $links) {}

    public function show(Request $request, string $token, ClientStatus $status): Response|RedirectResponse
    {
        $link = $this->links->findByLinkToken($token);

        if ($link instanceof StatusPageLink) {
            if (! $link->linkIsLive()) {
                return $this->expired($link->refusalReason());
            }

            return $this->withinTeamOf($link, function () use ($link): RedirectResponse {
                $session = $this->links->redeem($link);

                if ($session === null) {
                    /*
                     * Lost the race — two taps on a slow phone, or a mail
                     * scanner that fetched the link first. *Used* is the honest
                     * answer and S64 says what to do about it.
                     */
                    return $this->expired('used');
                }

                return redirect('/s/'.$session);
            });
        }

        $link = $this->links->findBySessionToken($token);

        if (! $link instanceof StatusPageLink || ! $link->sessionIsLive()) {
            return $this->expired($link?->refusalReason() ?? 'expired');
        }

        return $this->withinTeamOf($link, function () use ($link, $token, $status): Response {
            $this->links->touch($link);

            return $this->client('Status/Show', [
                'token' => $token,
                ...$status->for($link),
            ]);
        });
    }

    /**
     * S63 — the documents a client may have (F7.4).
     *
     * The **list**; the bytes are `StatusDocumentController`'s, through a
     * route that authorises the same way and writes an access entry. One path
     * to the bytes (#98), extended to the first reader with no session at all.
     */
    public function documents(string $token, ClientStatus $status): Response|RedirectResponse
    {
        $link = $this->links->findBySessionToken($token);

        if (! $link instanceof StatusPageLink || ! $link->sessionIsLive()) {
            return $this->expired($link?->refusalReason() ?? 'expired');
        }

        return $this->withinTeamOf(
            $link,
            fn (): Response|RedirectResponse => $this->documentsWithin($link, $token, $status),
        );
    }

    private function documentsWithin(StatusPageLink $link, string $token, ClientStatus $status): Response|RedirectResponse
    {
        $deal = $link->deal;

        if (! $deal instanceof Deal) {
            return $this->expired('expired');
        }

        /*
         * Counted here too. S19 answers *"has the client looked?"* from
         * `view_count` and `last_seen_at`, and only the timeline was touching
         * them — so a client who came back a week later to re-read a
         * disclosure read as not having come back at all, and `last_seen_at`
         * said the wrong week.
         *
         * `touch()` counts a **page view**, not a session, and a document list
         * is a page. The number is a rough signal an agent reads as *"they are
         * following along"*, not an audit figure — the audit figure for a
         * document is the entry `StatusDocumentController` writes when the
         * bytes are handed over.
         */
        $this->links->touch($link);

        $composed = $status->for($link);

        return $this->client('Status/Documents', [
            'token' => $token,
            'team' => $composed['team'] ?? [],
            'contact' => $composed['contact'] ?? [],
            'documents' => $status->documentQuery($deal)->get()->map(
                fn ($document): array => [
                    'id' => $document->getKey(),
                    'name' => $document->original_name,
                    /*
                     * No `scan_state`, no category vocabulary, no size in
                     * bytes. A badge reading *clean* over a photograph of a
                     * cheque would be believed, and *not scanned* is exactly
                     * the word IA §9 keeps off this surface. S63 shows a
                     * document or does not show it.
                     */
                    'url' => "/s/{$token}/documents/{$document->getKey()}",
                ],
            )->values()->all(),
        ]);
    }

    /**
     * S64 — expired, already used, or revoked, and which.
     */
    public function expiredPage(Request $request): Response
    {
        $reason = (string) $request->query('reason', 'expired');

        return $this->client('Status/Expired', [
            'reason' => in_array($reason, ['expired', 'used', 'revoked'], true) ? $reason : 'expired',
            /*
             * Whether they have just asked for a new one. A flag rather than a
             * flash message, because this screen is reached by a redirect from
             * itself and the confirmation has to survive that — and because
             * the sentence it shows is the *same* sentence whether or not the
             * address was one we know.
             */
            'requested' => $request->boolean('sent'),
        ]);
    }

    /**
     * S64's escape hatch — *"request a new one"* (#110).
     *
     * > It must not require the client to know anything but their email
     * > address.
     *
     * ## It always says the same thing
     *
     * Whether the address is on a deal or not, the answer is *"if we have that
     * address, a link is on its way."* Anything more specific turns this
     * endpoint into an oracle: a stranger with a list of addresses could learn
     * which of them are clients of this team, and on which deals. That is a
     * disclosure about somebody else's transaction, made to somebody who
     * proved nothing.
     *
     * ## Rate-limited per address as well as globally
     *
     * #110: *"the endpoint that emails a link is an email-sending endpoint
     * anyone can hit."* The route carries a global throttle; this adds the
     * per-address one, because a global limit alone lets one attacker spend
     * everybody's budget and lets a script mail one client fifty times.
     *
     * ## And it revokes what it replaces
     *
     * `IssueStatusPageLink::issue()` revokes the previous grant, which is what
     * makes an unauthenticated endpoint safe to leave open: the worst somebody
     * can do by asking is invalidate a link and cause an email to an address
     * they do not control.
     */
    public function request(Request $request, DispatchStatusPageLink $dispatch): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $address = mb_strtolower(trim((string) $validated['email']));

        $key = 'status-page-request:'.sha1($address);

        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_ADDRESS)) {
            /*
             * The same sentence as the success case, deliberately. A distinct
             * *"too many attempts"* would confirm that the address is worth
             * attacking, which is the disclosure this endpoint exists not to
             * make.
             */
            return $this->sent();
        }

        RateLimiter::increment($key, self::REQUEST_WINDOW_SECONDS);

        $dispatch->forAddress($address);

        return $this->sent();
    }

    private function sent(): RedirectResponse
    {
        return redirect('/s/expired?sent=1');
    }

    private function expired(string $reason): RedirectResponse
    {
        return redirect('/s/expired?reason='.$reason);
    }

    /**
     * Run the rest of the request inside the team the token belongs to.
     *
     * `StatusPageLink` is the one row a client's token can name, and it is
     * team-scoped like everything else — so the row itself answers *which
     * tenant*, and from there every ordinary scoped query is both correct and
     * pointed at exactly one team.
     *
     * **A team in the purge window resolves to nothing, and that is the right
     * refusal.** `Team` soft-deletes, so `$link->team` is null for a deleted
     * one; a client of a team that no longer exists gets S64 rather than a
     * page composed from rows on their way out (`CLAUDE.md`'s rule that a
     * soft-deleted tenant must not decide a fact that is not the tenant's —
     * here it decides one that *is*).
     *
     * @param  Closure(): (Response|RedirectResponse)  $work
     */
    private function withinTeamOf(StatusPageLink $link, Closure $work): Response|RedirectResponse
    {
        $team = $link->team;

        if (! $team instanceof Team) {
            return $this->expired('expired');
        }

        return app(TeamContext::class)->runFor($team, $work);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function client(string $component, array $props): Response
    {
        return Inertia::render($component, $props);
    }
}
