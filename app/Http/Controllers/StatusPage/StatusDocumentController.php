<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPage;

use App\Enums\ActivitySource;
use App\Enums\DocumentVisibility;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Document;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentStorage;
use App\Support\StatusPage\IssueStatusPageLink;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The bytes of a client-visible document (PRD §4.7 F7.4, §9 · issue #111).
 *
 * ## The first reader in this product with no session at all
 *
 * Slice 3 settled *one path to the bytes*: the viewer's preview and the
 * download both go through the subject's own audited route, so a rendered
 * preview is an access with an entry behind it and the authorization lives in
 * one place. That rule was written for a signed-in team member.
 *
 * S63 is the first reader with **no session**, only a magic-link token, so the
 * token is what authorises — and the audit entry has to record a *client's*
 * access rather than a team member's. That is new ground, not something #98
 * solved.
 *
 * **Never a presigned URL.** An entry written when a link is minted records an
 * intention, not a read; this is a read.
 *
 * ## Three things are checked, and the third is the one that is easy to miss
 *
 * The session is live, the document is `client_visible`, and the document
 * hangs off **this token's deal**. Without the third, a client with a live
 * session for one deal could name any client-visible document id in the
 * system — the shape `DocumentPolicy` records from the other direction, where
 * a policy keyed on the wrong subject produced a list somebody could see and
 * not open.
 *
 * ## The token establishes the tenant, and that has to be *done*
 *
 * Everything below the lookup — the link's deal, the client's membership, the
 * activity entry — is an ordinary scoped read, and a client has no resolved
 * team. So the work runs inside the link's own team (`TeamContext::runFor`),
 * which is what `StatusPageController` does one route along and for the same
 * reason. `Document` keeps its explicit `withoutTeamScope()` and its
 * `team_id` predicate: the narrowing there is written out against **this
 * link's** deal, and re-stating it is cheaper than depending on the wrapper
 * from a file that does not contain it.
 *
 * ## `actorPersonId` is null, deliberately
 *
 * A client is not a `people` row acting in the app — they are a membership the
 * team recorded. Putting their person id in the actor column would make an
 * audit reader believe somebody signed in. The membership is in `after`, where
 * it says what it is: *this client, through this link*.
 */
class StatusDocumentController extends Controller
{
    public function __construct(private readonly IssueStatusPageLink $links) {}

    public function __invoke(
        string $token,
        string $document,
        DocumentStorage $storage,
        AuditLogger $audit,
        RecordActivity $activity,
    ): HttpResponse {
        $link = $this->links->findBySessionToken($token);

        abort_unless($link instanceof StatusPageLink && $link->sessionIsLive(), 404);

        $team = $link->team;

        abort_unless($team instanceof Team, 404);

        return app(TeamContext::class)->runFor($team, fn (): HttpResponse => $this->serve(
            $link,
            $document,
            $storage,
            $audit,
            $activity,
        ));
    }

    private function serve(
        StatusPageLink $link,
        string $document,
        DocumentStorage $storage,
        AuditLogger $audit,
        RecordActivity $activity,
    ): HttpResponse {
        $deal = $link->deal;

        abort_unless($deal instanceof Deal, 404);

        /*
         * `withoutTeamScope()` because a client has no team resolved — the
         * token is what establishes it — and every narrowing the scope would
         * have done is written out below, against **this link's** deal.
         */
        $file = Document::withoutTeamScope()
            ->whereKey($document)
            ->where('team_id', $link->team_id)
            ->where('documentable_type', $deal->getMorphClass())
            ->where('documentable_id', $deal->getKey())
            ->where('visibility', DocumentVisibility::ClientVisible->value)
            ->first();

        abort_unless($file instanceof Document && $storage->exists($file), 404);

        /*
         * Written **before** the bytes are handed over, for the reason S52's
         * route gives: a read that failed halfway is still a read that
         * happened.
         */
        $audit->record(
            action: 'document.accessed_by_client',
            auditable: $file,
            teamId: $file->team_id,
            after: [
                'status_page_link_id' => $link->getKey(),
                'team_membership_id' => $link->team_membership_id,
                'deal_id' => $deal->getKey(),
            ],
        );

        /*
         * And on the deal's own timeline, because *"has the client seen it"* is
         * a question an agent asks and the audit log is not a screen they work
         * from (IA §11 keeps Activity and Audit apart for exactly this).
         */
        $activity->record(
            subject: $deal,
            eventType: 'status_page.document_downloaded',
            summary: ($link->membership?->fullName() ?? 'A client').' downloaded '.$file->original_name,
            source: ActivitySource::System,
            payload: ['documentId' => $file->getKey()],
            teamId: $file->team_id,
            deal: $deal,
        );

        return response($storage->contents($file), 200, [
            'Content-Type' => $file->mime_type,
            /*
             * **Always an attachment**, where S52 sometimes renders inline.
             * A client's browser rendering an HTML-ish file inline would run
             * it on this origin, and this is the one origin in the product a
             * stranger reaches without signing in. F7.4 is *"download only"*
             * in as many words.
             */
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_name,
                'document.'.pathinfo($file->path, PATHINFO_EXTENSION),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=60, no-store',
        ]);
    }
}
