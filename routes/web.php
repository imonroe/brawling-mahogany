<?php

declare(strict_types=1);

use App\Http\Controllers\People\ContactImportController;
use App\Http\Controllers\People\ContactLogController;
use App\Http\Controllers\People\PersonController;
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
     * The sidebar's remaining destinations (IA §5.1). Each renders a
     * placeholder naming the slice that replaces it, so the shell can be
     * navigated and reviewed — a nav item pointing at a 404 cannot be.
     */
    $placeholders = [
        'work' => ['My Work', 'S11', 2],
        'deals' => ['Deals', 'S13', 2],
        'properties' => ['Properties', 'S35', 2],
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
