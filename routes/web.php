<?php

declare(strict_types=1);

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\CalendarFeedController;
use App\Http\Controllers\Calendar\EventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dates\DateListController;
use App\Http\Controllers\Dates\DealDateController;
use App\Http\Controllers\Deals\AdvanceWorkflowController;
use App\Http\Controllers\Deals\ConfirmGateController;
use App\Http\Controllers\Deals\DealDocumentController;
use App\Http\Controllers\Deals\DealIndexController;
use App\Http\Controllers\Deals\DealOverviewController;
use App\Http\Controllers\Deals\DealPropertyController;
use App\Http\Controllers\Deals\DealTimelineController;
use App\Http\Controllers\Deals\DealWizardController;
use App\Http\Controllers\Deals\NoteController;
use App\Http\Controllers\Deals\OfferController;
use App\Http\Controllers\Deals\OverrideGateController;
use App\Http\Controllers\Deals\ParticipantController;
use App\Http\Controllers\Deals\StageStateController;
use App\Http\Controllers\Deals\StatusPageAccessController;
use App\Http\Controllers\Deals\TaskController;
use App\Http\Controllers\Deals\WorkflowAttachmentController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\Messages\MessageQueueController;
use App\Http\Controllers\Messages\MessageTemplateController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\People\ContactImportController;
use App\Http\Controllers\People\ContactLogController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\Properties\PhotoController;
use App\Http\Controllers\Properties\PropertyController;
use App\Http\Controllers\Properties\PropertyDealController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use App\Http\Controllers\Pwa\WebManifestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\StatusPage\StatusDocumentController;
use App\Http\Controllers\StatusPage\StatusPageController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\TeamSwitchController;
use App\Http\Controllers\Templates\AutomationController;
use App\Http\Controllers\Templates\StageTemplateController;
use App\Http\Controllers\Templates\TemplateController;
use App\Http\Controllers\WorkController;
use App\Http\Middleware\ClientSurfaceHeaders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

/*
 * The service worker (#102), from the root and outside `auth`.
 *
 * **The root is the whole point**: a worker controls only URLs at or below its
 * own path, so one served from `/build/sw.js` would install cleanly and
 * intercept nothing. `ServiceWorkerController` explains why this is a route
 * rather than a copied file or a web-server header.
 *
 * Outside `auth` because the browser re-fetches this script on its own
 * schedule to check for updates, and a session that expired between two of
 * those checks would otherwise get the sign-in page's HTML served back as
 * JavaScript. It carries no data — it is the same bytes for everybody, signed
 * in or not.
 */
Route::get('sw.js', ServiceWorkerController::class)->name('pwa.service-worker');

/*
 * The web app manifest, served rather than built (#102). See
 * `WebManifestController`: the plugin's own copy lands in `public/build` and
 * enters the worker's precache list as a relative URL that resolves to a path
 * nothing serves — and unlike the asset entries, no build hook can reach it.
 *
 * Outside `auth` for the reason the worker is: a browser fetches this to
 * decide whether the site is installable, sometimes before anybody has signed
 * in, and it is the same bytes for everybody.
 */
Route::get('manifest.webmanifest', WebManifestController::class)->name('pwa.manifest');

/*
 * The `.ics` feed itself (PRD §4.8 F8.3 · S60 · #108).
 *
 * **Outside `auth` and `team`.** The reader is Google's fetcher or Apple's,
 * with no cookie and no idea what a tenant is — the token establishes the
 * team, ADR 0002's stated exception and the same one the status page makes.
 *
 * Throttled, because #108 asks for it: a feed URL is pasted into services that
 * poll on their own schedule, and one that has been shared four times is four
 * pollers. The limit is generous — a calendar client fetches every few hours,
 * not every few seconds — so it bounds abuse without breaking a subscription
 * somebody legitimately has on a laptop, a phone and a shared team calendar.
 *
 * The `.ics` suffix is part of the path rather than a query parameter: several
 * clients decide how to treat a URL by its extension before they have read a
 * single header.
 */
Route::get('calendar/feeds/{token}.ics', [CalendarFeedController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('calendar.feeds.show');

/*
 * The client status page (PRD §4.7 · IA §6 · #110, #111).
 *
 * **Outside `auth`, outside `team`, and outside `verified`.** A client has no
 * account, no membership and no session — the token is what establishes the
 * tenant, which is ADR 0002's stated exception and the one an invitation
 * already makes.
 *
 * `/s/{token}` is IA §6's route, *"short and opaque"*, and it takes both of
 * #110's credentials: a 30-minute single-use link and the session it mints.
 * `StatusPageController` explains which is which and why the session token is
 * in the path rather than in a cookie.
 *
 * `/s/expired` is registered **before** the wildcard, or the word `expired`
 * would be read as a token — a 404 dressed as the very screen it should be.
 *
 * `ClientSurfaceHeaders` puts `no-referrer`, `noindex` and a private
 * `Cache-Control` on every one of these, which is a rule these four routes
 * need and nothing else does.
 */
Route::middleware(ClientSurfaceHeaders::class)->group(function (): void {
    Route::get('s/expired', [StatusPageController::class, 'expiredPage'])->name('status.expired');

    /*
     * S64's escape hatch, and an email-sending endpoint anybody can hit — so
     * it carries a global throttle as well as the per-address one the
     * controller applies. Neither alone is enough: a global limit lets one
     * attacker spend everybody's budget, and a per-address limit alone lets a
     * script walk a list.
     */
    Route::post('s/request', [StatusPageController::class, 'request'])
        ->middleware('throttle:10,1')
        ->name('status.request');

    /*
     * The bytes of a client-visible document, before the wildcard page route
     * so `{token}/documents/{document}` is not read as a page.
     */
    Route::get('s/{token}/documents/{document}', StatusDocumentController::class)
        ->name('status.documents.show');
    Route::get('s/{token}/documents', [StatusPageController::class, 'documents'])
        ->name('status.documents');

    Route::get('s/{token}', [StatusPageController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('status.show');
});

/*
 * Accepting an invitation happens before there is a membership to resolve, so
 * it sits outside the team middleware. The token is what establishes the team
 * (ADR 0002), and nothing else.
 */
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');

/*
 * The same acceptance, without the link (ADR 0003).
 *
 * Signed in as the invited address is the authorisation, so `auth` is the
 * only middleware that belongs: `team` would redirect the very person this
 * exists for — somebody with no membership anywhere — and `verified` and
 * `two-factor` gate the tenant application, which this is not yet.
 *
 * Throttled like its emailed twin. An id is not a secret, and walking them
 * against a signed-in session is the one probe this route makes possible.
 */
Route::post('invitations/{invitation}/claim', [InvitationController::class, 'claim'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.claim');

/*
 * Signed in, but with no live membership anywhere: the team switcher's "no
 * access" state (S09). Deliberately reachable without the `team` middleware,
 * which is what redirects here.
 */
Route::get('no-team', function () {
    /*
     * One prop, and it exists for the first five minutes of a fresh install.
     *
     * Before anybody is a platform administrator there is no way forward from
     * this screen at all: teams come from `/admin`, `/admin` needs the
     * privilege, and the privilege is set by a console command on purpose (a
     * screen that grants the highest access in the system is a screen worth
     * not having). So the screen says which command, but only while it is
     * true — a revoked member on a running install should be told to ask
     * their team, not handed operator instructions.
     */
    return Inertia::render('Teams/None', [
        /*
         * Cached, because the answer changes once in the life of an install
         * and the question is asked on every render of a screen somebody
         * lands on repeatedly while they wait for an invitation. A minute of
         * staleness on "somebody now administers this platform" costs one
         * refresh; a query per request costs it forever.
         */
        'platformHasNoAdministrator' => Cache::remember(
            'platform.has-administrator',
            now()->addMinute(),
            fn (): bool => App\Models\Person::query()->where('is_super_admin', true)->exists(),
        ) === false,
    ]);
})
    ->middleware('auth')
    ->name('teams.none');

/*
 * S92 — the manual (#170).
 *
 * **`auth` alone, outside `verified`, `two-factor` and `team`**, and each
 * exclusion is a case somebody is actually in.
 *
 * PRD §9 makes 2FA mandatory for a Team Owner, so an un-enrolled owner is held
 * at the enrolment screen — and *Signing in and your account* is the article
 * that explains enrolment, recovery codes and what to do when the phone is the
 * thing you lost. Leaving the manual inside `two-factor` locks the one person
 * who needs that page out of it.
 *
 * `team` is the same argument one step earlier: somebody invited but not yet
 * in a team lands on `/no-team`, and *What this is for* is what tells them
 * what they have been invited to.
 *
 * `verified` goes for the same reason — an unverified account is somebody
 * mid-signup, which is exactly when a manual is worth reading.
 *
 * The shell renders without a team already: `HandleInertiaRequests` returns
 * null for `team` and omits `counts` and `lookups` until one resolves, which
 * is what `/no-team` relies on.
 *
 * `{article}` is constrained to a slug, so the only thing reaching
 * `HelpLibrary` is something that could name a file; anything else is a 404
 * from the router rather than a lookup.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('help/{article}', [HelpController::class, 'show'])
        ->where('article', '[a-z0-9-]+')
        ->name('help.show');
});

/*
 * The tenant application.
 *
 * `two-factor` before `team`: PRD §9 makes 2FA mandatory for a Team Owner, and
 * a Team Owner who has not enrolled should meet the enrolment screen rather
 * than their dashboard.
 */
Route::middleware(['auth', 'verified', 'two-factor', 'team'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::put('teams/current', [TeamSwitchController::class, 'update'])->name('teams.switch');

    /*
     * S12 — the team activity feed.
     *
     * One route, no `store`: nothing writes to `activity_events` through a
     * screen. `RecordActivity` owns the table, and the one human-initiated
     * write the product has — logging a contact — goes through
     * `people/{membership}/contact-log` below, because F2.5 logs a contact
     * *against a person* and the person is what the URL has to carry.
     */
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    // S30, S31, S32 — the people directory.
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/lookup', [PersonController::class, 'lookup'])->name('people.lookup');
    /*
     * S26's person search, for the one entry point that has no person yet —
     * the shell's Log contact button. Registered here, before the wildcard
     * show route, so `/people/candidates` is never read as a membership id.
     */
    Route::get('people/candidates', [PersonController::class, 'candidates'])->name('people.candidates');
    Route::post('people', [PersonController::class, 'store'])->name('people.store');

    // S33 — contact import. Registered before the wildcard show route so
    // `/people/import` is never read as a membership id.
    Route::get('people/import', [ContactImportController::class, 'create'])->name('people.import.create');
    Route::post('people/import', [ContactImportController::class, 'store'])->name('people.import.store');
    Route::get('people/import/{import}', [ContactImportController::class, 'show'])->name('people.import.show');
    Route::post('people/import/{import}', [ContactImportController::class, 'commit'])->name('people.import.commit');

    Route::get('people/{membership}', [PersonController::class, 'show'])->name('people.show');
    Route::patch('people/{membership}', [PersonController::class, 'update'])->name('people.update');
    Route::delete('people/{membership}', [PersonController::class, 'destroy'])->name('people.destroy');
    Route::post('people/{membership}/contact-log', [ContactLogController::class, 'store'])->name('people.contact-log.store');

    /*
     * S19, S25 — deal people.
     *
     * `scopeBindings()` so `{participant}` is resolved *through* `{deal}`
     * rather than beside it. Without it, a participant id from one deal
     * reached through another deal's URL would bind happily — both rows are
     * in the team, so the global scope has no objection — and the policy
     * would agree. The tenancy layers answer "whose team", and only the
     * nesting answers "whose deal".
     */
    /*
     * S14 — create a deal.
     *
     * Every step posts, because the draft is the point: issue #74 requires a
     * half-finished deal to survive a dropped connection, so nothing lives in
     * component state. The draft is resolved from the **actor**, never from an
     * id in the URL — which is why none of these carries one, and why a draft
     * cannot be reached by guessing.
     *
     * **That day is #75.** `deals/create` was two segments and `deals/{deal}/…`
     * was three, so nothing could be read as a deal id; S15 added the
     * two-segment `deals/{deal}`, and the only thing keeping `/deals/create`
     * off it now is that these are registered first. Laravel matches in
     * registration order, so moving the wizard below the overview would turn
     * "New deal" into a 404 for a deal whose id is the word `create`.
     * `DealOverviewTest` holds it.
     */
    // S13 (#78). Registered before `deals/{deal}` for the reason above.
    Route::get('deals', [DealIndexController::class, 'index'])->name('deals.index');

    Route::get('deals/create', [DealWizardController::class, 'create'])->name('deals.create');
    Route::patch('deals/create', [DealWizardController::class, 'update'])->name('deals.draft.update');
    Route::post('deals/create', [DealWizardController::class, 'store'])->name('deals.draft.store');
    Route::delete('deals/create', [DealWizardController::class, 'destroy'])->name('deals.draft.destroy');
    Route::get('deals/create/clients', [DealWizardController::class, 'clients'])->name('deals.draft.clients');
    Route::post('deals/create/clients', [DealWizardController::class, 'storeClient'])
        ->name('deals.draft.clients.store');
    Route::get('deals/create/properties', [DealWizardController::class, 'properties'])
        ->name('deals.draft.properties');
    Route::post('deals/create/properties', [DealWizardController::class, 'storeProperty'])
        ->name('deals.draft.properties.store');

    /*
     * S28 — attach a workflow to a live deal (F4.7).
     *
     * Separate from the wizard because workflows arrive at different times:
     * the *Under Contract* one attaches when the offer is accepted, weeks
     * after the deal was created.
     */
    Route::get('deals/{deal}/workflows/available', [WorkflowAttachmentController::class, 'index'])
        ->name('deals.workflows.available');
    Route::post('deals/{deal}/workflows', [WorkflowAttachmentController::class, 'store'])
        ->name('deals.workflows.store');

    Route::scopeBindings()->group(function (): void {
        /*
         * S15 — the deal overview, and the deal's default landing (IA §5.2).
         *
         * Registered after `deals/create` and after the wizard's other
         * two-segment routes, which is what stops `create` binding as a deal
         * id. Inside `scopeBindings()` so the advance route below resolves its
         * `{workflow}` *through* `{deal}`: the tenancy layers answer "whose
         * team", and only the nesting answers "whose deal".
         */
        Route::get('deals/{deal}', [DealOverviewController::class, 'show'])
            ->name('deals.show');

        /*
         * F4.8 — the first HTTP caller `AdvanceWorkflow` has ever had.
         *
         * A POST rather than a PATCH on the workflow: advancing is an act with
         * consequences the client can see (a timeline entry, an audit row, and
         * in Slice 3 a message to a client), not an edit of a field.
         */
        Route::post('deals/{deal}/workflows/{workflow}/advance', [AdvanceWorkflowController::class, 'store'])
            ->name('deals.workflows.advance');

        /*
         * S23's own payload (#77).
         *
         * The same URI as the POST above, read rather than written — what
         * advancing *would* do. A modal opened from any of the eight deal tabs
         * cannot read it off a page prop, and it has to be current: the whole
         * value of the screen is that its refusal describes this minute.
         */
        Route::get('deals/{deal}/workflows/{workflow}/advance', [AdvanceWorkflowController::class, 'show'])
            ->name('deals.workflows.advance.preview');

        /*
         * S24 — override one gate with a reason (F4.9, #69).
         *
         * A separate route from advance because it is a separate permission
         * (`workflow.override`) and a separate act in the audit log. IA §7
         * calls conflating the two legally material, and a shared endpoint
         * with a mode flag is exactly that conflation in URL form.
         */
        Route::post('deals/{deal}/workflows/{workflow}/override', [OverrideGateController::class, 'store'])
            ->name('deals.workflows.override');

        /*
         * S23 — tick a manual gate, and untick one (F4.8).
         *
         * The routine way past the most common gate type, which the engine
         * shipped two slices without: `ManualConfirmationEvaluator` reads
         * `gates.is_met` and nothing wrote it, so the only way past one was
         * the override above — the audited exception standing in for the
         * ordinary path.
         *
         * `POST` and `DELETE` on a `confirmation` sub-resource, the shape
         * tasks use for completion, because confirming is not editing: only
         * one of the two writes a timeline entry and is counted by an advance.
         * Authorized on `advance`, not `override` — an assistant who advances
         * stages all day is exactly the person who confirms the survey came
         * back.
         */
        Route::post('deals/{deal}/workflows/{workflow}/confirmation', [ConfirmGateController::class, 'store'])
            ->name('deals.workflows.confirm');

        Route::delete('deals/{deal}/workflows/{workflow}/confirmation', [ConfirmGateController::class, 'destroy'])
            ->name('deals.workflows.unconfirm');

        /*
         * S22 — a deal's offers (F3.6, #73).
         *
         * `{offer}` resolves through `{deal}` by scoped binding: two deals in
         * the same team pass the tenancy layers and the policy alike, and only
         * the nesting answers whose deal an offer is on.
         */
        Route::get('deals/{deal}/offers', [OfferController::class, 'index'])
            ->name('deals.offers.index');
        Route::post('deals/{deal}/offers', [OfferController::class, 'store'])
            ->name('deals.offers.store');
        Route::patch('deals/{deal}/offers/{offer}', [OfferController::class, 'update'])
            ->name('deals.offers.update');
        Route::delete('deals/{deal}/offers/{offer}', [OfferController::class, 'destroy'])
            ->name('deals.offers.destroy');

        /*
         * S21 — a deal's documents (F6.1–F6.3, F6.7 · #98, #99, #100).
         *
         * The general upload path Slice 2 deliberately did not build. #63
         * closed its residual window by restricting the context — images only,
         * against a property only — and this exists because
         * `SensitiveContent` inspects the bytes instead, so a photographed
         * cheque is refused on what it is rather than on where it was going.
         *
         * `{document}` resolves through `{deal}` by scoped binding, like
         * `{offer}` above: only the nesting answers whose deal it is on.
         */
        Route::get('deals/{deal}/documents', [DealDocumentController::class, 'index'])
            ->name('deals.documents.index');
        Route::post('deals/{deal}/documents', [DealDocumentController::class, 'store'])
            ->name('deals.documents.store');
        /*
         * Streamed through the application, never a presigned URL: PRD §9
         * makes document access an audited event, and an entry written when a
         * link is minted records an intention rather than a read.
         */
        Route::get('deals/{deal}/documents/{document}', [DealDocumentController::class, 'show'])
            ->name('deals.documents.show');
        Route::delete('deals/{deal}/documents/{document}', [DealDocumentController::class, 'destroy'])
            ->name('deals.documents.destroy');

        /*
         * F4.11 — a note on a deal (#72).
         *
         * Nested under the deal because a note is *about* one, and inside
         * `scopeBindings()` with everything else here. IA §7: a note is
         * **written** and a contact is **logged** — `people/{membership}/
         * contacts` is the other verb, and they are deliberately not one
         * endpoint with a type flag.
         */
        Route::post('deals/{deal}/notes', [NoteController::class, 'store'])
            ->name('deals.notes.store');

        /*
         * F4.12 — the two stage verbs that are not Advance (#70).
         *
         * Two routes rather than one with a mode flag, for the reason the
         * override has its own: IA §7 calls conflating Skip with Override
         * legally material, and Reopen is a third act again. A shared endpoint
         * is that conflation in URL form, and the audit log would inherit it.
         *
         * `{stage}` resolves through `{workflow}` by scoped binding — one deal
         * runs several workflows at once (F4.7), so "whose workflow" is a
         * question neither the tenancy layers nor the policy can answer.
         */
        Route::post('deals/{deal}/workflows/{workflow}/stages/{stage}/skip', [StageStateController::class, 'skip'])
            ->name('deals.workflows.stages.skip');

        Route::post('deals/{deal}/workflows/{workflow}/stages/{stage}/reopen', [StageStateController::class, 'reopen'])
            ->name('deals.workflows.stages.reopen');

        /*
         * S16 — the stage rail (#76).
         *
         * A GET and nothing else. Every action the screen offers already has
         * its own route — both of the POSTs directly above — and the rail
         * reaches them through `useAdvanceDialog`, the same way every other
         * deal tab does. A timeline that could be posted to would be a second
         * way into `AdvanceWorkflow`, which is the one thing this codebase
         * keeps single.
         */
        Route::get('deals/{deal}/timeline', [DealTimelineController::class, 'index'])
            ->name('deals.timeline');

        /*
         * The agent's half of the client status page (#110).
         *
         * On the People tab because that is where the roster is, and *"can
         * Dana see this deal"* is a fact about Dana's place on it rather than
         * a setting. `link` is ADR 0003's second door: the URL handed to the
         * agent, for the phone call or the text message.
         */
        Route::post('deals/{deal}/people/{membership}/status-page', [StatusPageAccessController::class, 'send'])
            ->middleware('throttle:20,1')
            ->name('deals.status-page.send');
        Route::post('deals/{deal}/people/{membership}/status-page/link', [StatusPageAccessController::class, 'handOver'])
            ->middleware('throttle:20,1')
            ->name('deals.status-page.link');
        Route::delete('deals/{deal}/people/{membership}/status-page', [StatusPageAccessController::class, 'revoke'])
            ->name('deals.status-page.revoke');

        Route::get('deals/{deal}/people', [ParticipantController::class, 'index'])
            ->name('deals.people.index');
        Route::get('deals/{deal}/people/candidates', [ParticipantController::class, 'candidates'])
            ->name('deals.people.candidates');
        Route::post('deals/{deal}/people', [ParticipantController::class, 'store'])
            ->name('deals.people.store');
        Route::patch('deals/{deal}/people/{participant}', [ParticipantController::class, 'update'])
            ->name('deals.people.update');
        Route::delete('deals/{deal}/people/{participant}', [ParticipantController::class, 'remove'])
            ->name('deals.people.remove');

        /*
         * S17, S27 — a deal's tasks (#71).
         *
         * `{task}` resolves *through* `{deal}` — `Deal::tasks()` is the
         * relation `scopeBindings()` walks — which is what answers "whose
         * deal". The tenancy layers only answer "whose team", and a task id
         * from the deal next door would bind happily without it.
         *
         * **Completion is its own sub-resource**, posted to and deleted from,
         * rather than a boolean on the PATCH. The two are different acts: an
         * edit changes what the work is, and completing it says the work is
         * done — which writes an activity event and is what the
         * `required_tasks_complete` gate is counting. A shared endpoint with a
         * flag makes "I fixed a typo" and "it is done" the same request, which
         * is the shape IA §7 objects to when Override and Skip share a label.
         */
        /*
         * S18 — a deal's Dates & Deadlines (F8.2 · #106, #107).
         *
         * The cascade preview is its own `POST` rather than a query parameter
         * on the tab, because it is a *computation over a proposed change*
         * that writes nothing — and a GET carrying a proposed date would be a
         * URL somebody could bookmark and re-run against a deal that has since
         * moved. `SaveKeyDate::preview()` and `::edit()` are the same
         * computation, which is what makes the preview honest.
         */
        Route::get('deals/{deal}/dates', [DealDateController::class, 'index'])
            ->name('deals.dates.index');
        Route::post('deals/{deal}/dates', [DealDateController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('deals.dates.store');
        Route::post('deals/{deal}/dates/preview', [DealDateController::class, 'preview'])
            ->middleware('throttle:120,1')
            ->name('deals.dates.preview');
        Route::patch('deals/{deal}/dates/{keyDate}', [DealDateController::class, 'update'])
            ->middleware('throttle:60,1')
            ->name('deals.dates.update');
        Route::delete('deals/{deal}/dates/{keyDate}', [DealDateController::class, 'destroy'])
            ->name('deals.dates.destroy');

        Route::get('deals/{deal}/tasks', [TaskController::class, 'index'])
            ->name('deals.tasks.index');
        Route::post('deals/{deal}/tasks', [TaskController::class, 'store'])
            ->name('deals.tasks.store');
        Route::patch('deals/{deal}/tasks/{task}', [TaskController::class, 'update'])
            ->name('deals.tasks.update');
        Route::post('deals/{deal}/tasks/{task}/completion', [TaskController::class, 'complete'])
            ->name('deals.tasks.complete');
        Route::delete('deals/{deal}/tasks/{task}/completion', [TaskController::class, 'reopen'])
            ->name('deals.tasks.reopen');
        Route::delete('deals/{deal}/tasks/{task}', [TaskController::class, 'destroy'])
            ->name('deals.tasks.destroy');

        /*
         * S20 — deal properties.
         *
         * `{propertyLink}`, because scoped binding resolves the child through
         * a relation named for the parameter (`Str::plural(Str::camel(...))`),
         * and `Deal::propertyLinks()` is that relation. The nesting is what
         * answers "whose deal" — the tenancy layers only answer "whose team",
         * and a link row from another deal in the same team would bind
         * happily without it.
         *
         * `candidates` and `order` are registered before the wildcard so
         * neither is ever read as a link id.
         */
        Route::get('deals/{deal}/properties', [DealPropertyController::class, 'index'])
            ->name('deals.properties.index');
        Route::get('deals/{deal}/properties/candidates', [DealPropertyController::class, 'candidates'])
            ->name('deals.properties.candidates');
        Route::put('deals/{deal}/properties/order', [DealPropertyController::class, 'rank'])
            ->name('deals.properties.rank');
        Route::post('deals/{deal}/properties', [DealPropertyController::class, 'store'])
            ->name('deals.properties.store');
        Route::patch('deals/{deal}/properties/{propertyLink}', [DealPropertyController::class, 'update'])
            ->name('deals.properties.update');
        Route::post('deals/{deal}/properties/{propertyLink}/subject', [DealPropertyController::class, 'promote'])
            ->name('deals.properties.promote');
        Route::delete('deals/{deal}/properties/{propertyLink}', [DealPropertyController::class, 'remove'])
            ->name('deals.properties.remove');
    });

    /*
     * S35, S36, S37 — properties.
     *
     * `scopeBindings()` on the nested pair so `{link}` is resolved *through*
     * `{property}` rather than beside it. Without it, a link row from another
     * property would bind happily — both rows are in the team, so the global
     * scope has no objection, and the policy is asked about the property. The
     * tenancy layers answer "whose team"; only the nesting answers "whose
     * property".
     */
    /*
     * S50 — every document the team holds (F6.1 · #98).
     *
     * A team-level list beside `/properties` rather than under a deal: the
     * deal tab answers "what is on this deal", and this answers "where is that
     * disclosure", which is asked from a standing start.
     */
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    /*
     * S52. The viewer is team-level rather than nested under a deal, because a
     * document reached from S50 has no deal in the URL to nest under — and the
     * **bytes** are not served here: the preview and the download both go
     * through the subject's own audited route, so there is exactly one path to
     * a file and one place the authorization lives.
     */
    Route::get('documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');
    Route::patch('documents/{document}/visibility', [DocumentController::class, 'updateVisibility'])
        ->name('documents.visibility.update');

    /*
     * S57 and S58 — the calendar (F8.1 · #105).
     *
     * `/calendar` is IA §5.1's sidebar destination and has been since Slice 0;
     * this replaces the placeholder that stood there. The event modal posts to
     * `/calendar/events` rather than to a top-level `/events`, because an event
     * has no life outside the grid it is drawn on — IA §6 allows one level of
     * nesting and this is what it is for.
     */
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    /*
     * S60's generate and revoke (#108). The modal opens over S57, so its list
     * rides in that screen's props and there is no `index` route here.
     */
    Route::post('calendar/feeds', [CalendarFeedController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('calendar.feeds.store');
    Route::delete('calendar/feeds/{feed}', [CalendarFeedController::class, 'destroy'])
        ->name('calendar.feeds.destroy');

    Route::post('calendar/events', [EventController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('calendar.events.store');
    Route::patch('calendar/events/{event}', [EventController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('calendar.events.update');
    Route::delete('calendar/events/{event}', [EventController::class, 'destroy'])
        ->name('calendar.events.destroy');

    /*
     * S59 — every deadline across every deal (F8.2 · #107).
     *
     * `/dates` at the top level, beside `/work`: it answers *"what is this
     * week's exposure"* from a standing start, which is a question with no
     * deal in mind. The UI calls it **Dates & Deadlines** (IA §2, Emily's
     * phrase); the route keeps the code name.
     */
    Route::get('dates', [DateListController::class, 'index'])->name('dates.index');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::get('properties/{property}', [PropertyController::class, 'show'])->name('properties.show');
    Route::patch('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');

    Route::scopeBindings()->group(function (): void {
        Route::get('properties/{property}/deals/candidates', [PropertyDealController::class, 'candidates'])
            ->name('properties.deals.candidates');
        Route::post('properties/{property}/deals', [PropertyDealController::class, 'store'])
            ->name('properties.deals.store');
        /*
         * `{dealLink}`, because scoped binding resolves the child through a
         * relation named for the parameter — `Str::plural(Str::camel(...))`,
         * so `dealLinks()` on `Property`. A shorter `{link}` would have looked
         * for `links()`, fallen through `__call` to the query builder, and
         * thrown `BadMethodCallException` on every request to this route.
         */
        Route::delete('properties/{property}/deals/{dealLink}', [PropertyDealController::class, 'remove'])
            ->name('properties.deals.remove');

        /*
         * S38 — a property's photographs (F6.4–F6.6, #63).
         *
         * The product's **only** upload path in this slice, and it hangs off a
         * property rather than a deal on purpose: #63's residual window is
         * closed by restricting the context, because a photographed cheque is
         * an image and the content scan is #100's work.
         *
         * `download` is a route rather than a presigned object-store URL. PRD
         * §9 makes document access an audited event, and an entry written when
         * a link was minted records an intention rather than a read.
         */
        Route::post('properties/{property}/photos', [PhotoController::class, 'store'])
            ->name('properties.photos.store');
        Route::patch('properties/{property}/photos', [PhotoController::class, 'reorder'])
            ->name('properties.photos.reorder');
        Route::post('properties/{property}/photos/{photo}/primary', [PhotoController::class, 'setPrimary'])
            ->name('properties.photos.primary');
        Route::delete('properties/{property}/photos/{photo}', [PhotoController::class, 'destroy'])
            ->name('properties.photos.destroy');
        Route::get('properties/{property}/photos/{photo}', [PhotoController::class, 'download'])
            ->name('properties.photos.show');
    });

    /*
     * S07 — the global search overlay (F9.3, #82).
     *
     * JSON, because the overlay opens over whatever screen somebody is on and
     * an Inertia visit would replace it. `q` in the query string so a search
     * is a GET that can be retried and cached by nothing.
     */
    Route::get('search', SearchController::class)->name('search');

    /*
     * The sidebar's remaining destinations (IA §5.1). Each renders a
     * placeholder naming the slice that replaces it, so the shell can be
     * navigated and reviewed — a nav item pointing at a 404 cannot be.
     */
    /*
     * S11 — My Work (F9.2, #80). Heather's primary screen, and the reason
     * `tasks.deal_id` is not nullable: a task belonging to nothing has nowhere
     * to appear here.
     */
    Route::get('work', [WorkController::class, 'index'])->name('work.index');

    /*
     * S39–S43 — templates (F4.1, #84–#86).
     *
     * A team's own templates are editable and a pack's are not: one pack is
     * shared by every team, so `WorkflowTemplatePolicy::update()` refuses a
     * system row and the pack browser's only verb is *Use a copy*. Every
     * nested route authorizes against the **workflow template**, because a
     * guard on the parent with a door beside it is not a guard.
     */
    /*
     * S45, S46 — message templates (F5.5, F5.6, #90).
     *
     * **Registered before `templates/{template}`**, and the order is
     * load-bearing: Laravel matches in registration order, so a
     * `templates/{template}` declared first would swallow `templates/messages`
     * and answer a 404 for a screen that exists.
     *
     * The path says *messages* where the Screen Inventory said *emails*. PRD
     * §7.12 is the correction — a template carries a channel, and `push` is
     * one of them — so a route named after one channel would be wrong the
     * first time somebody wrote a push template. There is **no destroy
     * route**: an automation points at a template, so a template is archived
     * and never deleted (Frontend conventions §4).
     */
    Route::get('templates/messages', [MessageTemplateController::class, 'index'])
        ->name('message-templates.index');
    /*
     * Throttled for the same reason `preview` is, and reasoned about as its
     * pair: these two run the identical merge-field scan over the identical
     * 300 KB of body, and then write. A ceiling on the route that computes and
     * none on the routes that compute *and* write is half an answer.
     */
    Route::post('templates/messages', [MessageTemplateController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('message-templates.store');
    Route::get('templates/messages/{messageTemplate}', [MessageTemplateController::class, 'show'])
        ->name('message-templates.show');
    Route::patch('templates/messages/{messageTemplate}', [MessageTemplateController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('message-templates.update');
    Route::post('templates/messages/{messageTemplate}/archive', [MessageTemplateController::class, 'archive'])
        ->name('message-templates.archive');
    Route::post('templates/messages/{messageTemplate}/restore', [MessageTemplateController::class, 'restore'])
        ->name('message-templates.restore');
    /*
     * The live preview and the test send. Both POST because both carry the
     * unsaved draft — the preview's whole job is showing what is in the form
     * rather than what is in the database.
     */
    /*
     * Throttled too, and more loosely: this is the route somebody presses
     * while typing. It accepts up to 300 KB across three fields and scans all
     * of it — taking `<style>` blocks out of a markup body is quadratic on an
     * unclosed one, measured at 483ms against 65ms for the same size of
     * ordinary email. It needs an authenticated member of the team, so the
     * bound is somebody heating their own instance; a ceiling is still cheaper
     * than finding out.
     */
    Route::post('templates/messages/{messageTemplate}/preview', [MessageTemplateController::class, 'preview'])
        ->middleware('throttle:60,1')
        ->name('message-templates.preview');
    /*
     * Throttled, because this is the first route in the product that sends
     * anything. It can only reach the person who pressed it, so the blast
     * radius is their own inbox — but F5.9's per-team rate limit is a named
     * launch blocker (#96) and a send path with no ceiling at all is the shape
     * that blocker exists to refuse.
     */
    Route::post('templates/messages/{messageTemplate}/test', [MessageTemplateController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('message-templates.test');

    /*
     * S47, S48, S49 — the approval queue, the preview, and one message's
     * record (F5.7, F5.8, #93).
     *
     * PRD §4.5 calls the queue a **launch blocker, not an enhancement**, and
     * there is deliberately no bulk-approve route: #93 says *"bulk approve
     * teaches people to approve without reading"*, and a route that took an
     * array of ids would be that feature whatever the screen chose to draw.
     *
     * No destroy either. Stopping a message is `cancel` — IA §7's verb, and
     * not Delete: S49 has to be able to answer *"why did the client never hear
     * about this"* months later, and a deleted row answers nothing.
     *
     * `/messages` rather than `/deals/{deal}/messages`, because the question
     * this screen answers is *"what needs me"* across every deal at once. One
     * message's own page links back to the deal it belongs to.
     */
    /*
     * S08 (#101). No permission and no policy: the query is keyed on the
     * person asking, so the predicate *is* the authorization — and it reads
     * across every team they are in, which is the one screen in the product
     * that does.
     */
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])
        ->name('notifications.read');
    /*
     * The opener: switch to the notification's own team, then redirect. A
     * plain link to the deal 404s for a cross-team notification, which is the
     * one case the panel exists to serve.
     */
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])
        ->name('notifications.open');

    Route::get('messages', [MessageQueueController::class, 'index'])
        ->name('messages.index');
    Route::get('messages/{message}', [MessageQueueController::class, 'show'])
        ->name('messages.show');
    /*
     * Throttled, because this is the route that puts an email on a transport.
     * A ceiling here is not F5.9's — that one is per team and lives in the
     * worker, where it catches the 3am scheduled send with no human present —
     * it is the ordinary bound on a write endpoint somebody can hold down.
     */
    Route::post('messages/{message}/approval', [MessageQueueController::class, 'approve'])
        ->middleware('throttle:30,1')
        ->name('messages.approve');
    Route::delete('messages/{message}/approval', [MessageQueueController::class, 'cancel'])
        ->name('messages.cancel');

    Route::scopeBindings()->group(function (): void {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
        Route::patch('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('templates/{template}/copy', [TemplateController::class, 'copy'])->name('templates.copy');

        Route::post('templates/{template}/stages', [StageTemplateController::class, 'store'])
            ->name('templates.stages.store');
        Route::patch('templates/{template}/stages', [StageTemplateController::class, 'reorder'])
            ->name('templates.stages.reorder');
        Route::patch('templates/{template}/stages/{stageTemplate}', [StageTemplateController::class, 'update'])
            ->name('templates.stages.update');
        Route::delete('templates/{template}/stages/{stageTemplate}', [StageTemplateController::class, 'destroy'])
            ->name('templates.stages.destroy');
        Route::post('templates/{template}/stages/{stageTemplate}/gates', [StageTemplateController::class, 'addGate'])
            ->name('templates.stages.gates.store');
        Route::delete('templates/{template}/stages/{stageTemplate}/gates/{gateTemplate}', [StageTemplateController::class, 'removeGate'])
            ->name('templates.stages.gates.destroy');
        Route::post('templates/{template}/stages/{stageTemplate}/tasks', [StageTemplateController::class, 'addTask'])
            ->name('templates.stages.tasks.store');
        Route::delete('templates/{template}/stages/{stageTemplate}/tasks/{taskTemplate}', [StageTemplateController::class, 'removeTask'])
            ->name('templates.stages.tasks.destroy');

        /*
         * S44 — automations on a stage (F5.1–F5.4, #91).
         *
         * The path segment reads `automations` because that is the only word
         * for the thing (IA §11); the bound parameter is `{actionDefinition}`
         * because `scopeBindings()` derives the relation name from it, and the
         * relation is `StageTemplate::actionDefinitions()`. The table keeps
         * PRD §6.2's name.
         */
        Route::post('templates/{template}/stages/{stageTemplate}/automations', [AutomationController::class, 'store'])
            ->name('templates.stages.automations.store');
        Route::patch('templates/{template}/stages/{stageTemplate}/automations/{actionDefinition}', [AutomationController::class, 'update'])
            ->name('templates.stages.automations.update');
        Route::delete('templates/{template}/stages/{stageTemplate}/automations/{actionDefinition}', [AutomationController::class, 'destroy'])
            ->name('templates.stages.automations.destroy');
    });

    /*
     * S75 — roles and permissions (F2.3, #88).
     *
     * **No destroy route**, deliberately. A lookup is archived, never deleted
     * — the rule S76 set — because a role appears in audit entries and in
     * every membership that ever held it.
     */
    Route::get('settings/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('settings/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::patch('settings/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('settings/roles/{role}/archive', [RoleController::class, 'archive'])->name('roles.archive');
    Route::post('settings/roles/{role}/restore', [RoleController::class, 'restore'])->name('roles.restore');

    $placeholders = [
        'keep-in-touch' => ['Keep in Touch', 'S68', 6],
    ];

    foreach ($placeholders as $path => [$title, $screen, $slice]) {
        Route::inertia($path, 'Placeholder', [
            'title' => $title,
            'screen' => $screen,
            'slice' => $slice,
        ])->name(str_replace('-', '_', $path).'.index');
    }
});

/*
 * The component gallery. An internal review surface for the design system,
 * not a product screen — it is never served in production.
 */
if (! app()->isProduction()) {
    Route::inertia('design-system', 'DesignSystem/Gallery')
        ->middleware(['auth'])
        ->name('design-system');
}

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
