<?php

declare(strict_types=1);

use App\Http\Controllers\Deals\DealPropertyController;
use App\Http\Controllers\Deals\DealWizardController;
use App\Http\Controllers\Deals\ParticipantController;
use App\Http\Controllers\Deals\WorkflowAttachmentController;
use App\Http\Controllers\People\ContactImportController;
use App\Http\Controllers\People\ContactLogController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\Properties\PropertyController;
use App\Http\Controllers\Properties\PropertyDealController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\TeamSwitchController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

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
 * The tenant application.
 *
 * `two-factor` before `team`: PRD §9 makes 2FA mandatory for a Team Owner, and
 * a Team Owner who has not enrolled should meet the enrolment screen rather
 * than their dashboard.
 */
Route::middleware(['auth', 'verified', 'two-factor', 'team'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::put('teams/current', [TeamSwitchController::class, 'update'])->name('teams.switch');

    // S30, S31, S32 — the people directory.
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/lookup', [PersonController::class, 'lookup'])->name('people.lookup');
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
     * `deals/create` is two segments and `deals/{deal}/…` is three, so nothing
     * here can be read as a deal id; it is registered first anyway, because
     * the day somebody adds `deals/{deal}` that stops being true.
     */
    /*
     * S13 is #78 and still a placeholder — but it is where "New Deal" lives
     * (PRD §5.2 step 1), so it is rendered here rather than left pointing at
     * a route that does not exist.
     */
    Route::inertia('deals', 'Deals/Index', ['screen' => 'S13', 'slice' => 2])->name('deals.index');

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
    });

    /*
     * The sidebar's remaining destinations (IA §5.1). Each renders a
     * placeholder naming the slice that replaces it, so the shell can be
     * navigated and reviewed — a nav item pointing at a 404 cannot be.
     */
    $placeholders = [
        'work' => ['My Work', 'S11', 2],
        'calendar' => ['Calendar', 'S57', 4],
        'keep-in-touch' => ['Keep in Touch', 'S68', 6],
        'templates' => ['Templates', 'S40', 2],
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
