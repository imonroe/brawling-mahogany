<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\TeamController;
use Illuminate\Support\Facades\Route;

/*
 * The super admin console (PRD §4.1 F1.5 · IA §5.5 · Screen Inventory S81–S85).
 *
 * A separate route namespace with its own layout, *"visually distinct from the
 * tenant app so nobody ever confuses the two."*
 *
 * `super-admin` throws a **404**, not a 403 (issue #52): a 403 confirms the
 * namespace exists, which is the one thing the response must not say.
 *
 * Notably absent from this group: `team`. The console is outside every team's
 * context by definition, and each of its cross-tenant reads goes through the
 * explicit, audited `runWithoutScope()` bypass instead.
 */
Route::middleware(['auth', 'verified', 'two-factor', 'super-admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
        Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show');
        Route::post('teams/{team}/suspend', [TeamController::class, 'suspend'])->name('teams.suspend');
        Route::post('teams/{team}/restore', [TeamController::class, 'restore'])->name('teams.restore');

        // S84 — impersonation.
        Route::get('teams/{team}/impersonate', [ImpersonationController::class, 'create'])->name('impersonate.create');
        Route::post('teams/{team}/impersonate', [ImpersonationController::class, 'store'])->name('impersonate.store');

        Route::get('health', HealthController::class)->name('health');
        Route::get('audit', AuditController::class)->name('audit');
    });

/*
 * Ending a support session is deliberately outside `super-admin`.
 *
 * By the time somebody wants out, the authenticated person is the customer, so
 * a guard checking `is_super_admin` would trap the administrator inside the
 * session they are trying to leave.
 */
Route::delete('impersonation', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonation.destroy');
