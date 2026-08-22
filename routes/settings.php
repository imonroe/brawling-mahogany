<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TeamController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * A person's own account. Deliberately outside the `team` middleware: somebody
 * with no live membership must still be able to reach their profile, change
 * their password, and — the case that matters — finish the 2FA enrolment the
 * mandate is holding them at.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'Settings/Appearance')->name('appearance.edit');
});

/*
 * The team's own settings. These need a resolved team and an enrolled Team
 * Owner, so they carry the full middleware the tenant app does.
 */
Route::middleware(['auth', 'verified', 'two-factor', 'team'])->group(function (): void {
    // S72 — team profile and branding.
    Route::get('settings/team', [TeamController::class, 'edit'])->name('team.edit');
    Route::patch('settings/team', [TeamController::class, 'update'])->name('team.update');

    // S74 — members and invitations.
    Route::get('settings/members', [MemberController::class, 'index'])->name('members.index');
    Route::post('settings/members/invitations', [MemberController::class, 'invite'])->name('members.invite');
    Route::delete('settings/members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])
        ->name('members.invitations.revoke');
    Route::delete('settings/members/{membership}', [MemberController::class, 'revoke'])->name('members.revoke');

    // S79 — team data export.
    Route::get('settings/export', [DataExportController::class, 'index'])->name('export.index');
    Route::post('settings/export', [DataExportController::class, 'store'])->name('export.store');
});

/*
 * The download is signed and expiring (PRD §9), which is what carries the
 * authorisation across a link somebody may open from an email.
 */
Route::get('settings/export/{export}/download', [DataExportController::class, 'download'])
    ->middleware(['auth', 'verified', 'team', 'signed'])
    ->name('export.download');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
