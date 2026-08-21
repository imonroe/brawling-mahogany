<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    /*
     * The sidebar's destinations (IA §5.1). Slice 0 builds the shell, not the
     * screens, so each of these renders a placeholder naming the slice that
     * replaces it. They exist so the shell can be navigated and reviewed —
     * a nav item pointing at a 404 cannot be.
     */
    $placeholders = [
        'work' => ['My Work', 'S11', 2],
        'deals' => ['Deals', 'S13', 2],
        'people' => ['People', 'S30', 1],
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
