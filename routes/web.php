<?php

declare(strict_types=1);

use App\Http\Controllers\People\ContactImportController;
use App\Http\Controllers\People\ContactLogController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\TeamSwitchController;
use Illuminate\Support\Facades\Route;

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
Route::inertia('no-team', 'Teams/None')
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
