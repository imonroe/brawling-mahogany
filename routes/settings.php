<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Push\PushSubscriptionController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\DealTypeController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SendSafetyController;
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
    /*
     * The logo, which is three routes rather than a field on the form above.
     *
     * A file is not a string: it needs a multipart request the `PATCH` cannot
     * carry through Inertia without method spoofing, it is replaced rather
     * than edited, and it is read back by a `GET` that streams bytes instead
     * of rendering a page. `POST` and `DELETE` on the thing itself, the same
     * shape S38's photographs already use.
     *
     * The read is authorized like every other private file in the product —
     * never a public URL. An email cannot use this route at all (a client has
     * no session), which is why S86 embeds the bytes instead.
     */
    Route::post('settings/team/logo', [TeamController::class, 'storeLogo'])->name('team.logo.store');
    Route::delete('settings/team/logo', [TeamController::class, 'destroyLogo'])->name('team.logo.destroy');
    Route::get('settings/team/logo', [TeamController::class, 'showLogo'])->name('team.logo.show');

    // S74 — members and invitations.
    Route::get('settings/members', [MemberController::class, 'index'])->name('members.index');
    Route::post('settings/members/invitations', [MemberController::class, 'invite'])->name('members.invite');
    // ADR 0003: an invitation must be deliverable by hand as well as by mail.
    Route::post('settings/members/invitations/{invitation}/link', [MemberController::class, 'issueLink'])
        ->name('members.invitations.link');
    Route::delete('settings/members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])
        ->name('members.invitations.revoke');
    Route::delete('settings/members/{membership}', [MemberController::class, 'revoke'])->name('members.revoke');

    /*
     * S76 — deal types.
     *
     * No `destroy`, deliberately. A lookup that live deals point at cannot be
     * removed without orphaning them, so the screen offers archive and restore
     * instead — see `DealTypeController`'s docblock. A route that did not
     * exist is a route nobody can reach by guessing the verb.
     */
    Route::get('settings/deal-types', [DealTypeController::class, 'index'])->name('deal-types.index');
    Route::post('settings/deal-types', [DealTypeController::class, 'store'])->name('deal-types.store');
    Route::patch('settings/deal-types/{dealType}', [DealTypeController::class, 'update'])
        ->name('deal-types.update');
    Route::post('settings/deal-types/{dealType}/archive', [DealTypeController::class, 'archive'])
        ->name('deal-types.archive');
    Route::post('settings/deal-types/{dealType}/restore', [DealTypeController::class, 'restore'])
        ->name('deal-types.restore');

    /*
     * F5.9's rails, where a person can reach them (#96).
     *
     * Its own screen rather than a panel on S72, because this is the one
     * somebody opens in a hurry after a client phones — and burying the stop
     * button under a colour picker is how it takes forty seconds to find
     * instead of five. There is no separate "stop" route: the switch is a
     * field on the same form, so a team cannot end up with sending off and a
     * screen that has not noticed.
     */
    Route::get('settings/sending', [SendSafetyController::class, 'edit'])->name('team.send-safety.edit');
    Route::patch('settings/sending', [SendSafetyController::class, 'update'])->name('team.send-safety.update');

    // S79 — team data export.
    /*
     * S78 (#101). Inside the team group but behind no permission: everybody
     * chooses how they are told, and the row is keyed on the person asking.
     */
    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])
        ->name('notification-preferences.edit');
    Route::patch('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('notification-preferences.update');

    /*
     * S55 (#103), beside S78 because it is the same screen.
     *
     * A subscription belongs to a **browser**, and a browser belongs to a
     * person who may be in two teams — so these deliberately do not care
     * which team is resolved, and switching teams neither registers nor
     * forgets a device. They sit in this group only because that is where the
     * screen lives.
     */
    Route::post('settings/notifications/push', [PushSubscriptionController::class, 'store'])
        ->name('push-subscriptions.store');
    Route::delete('settings/notifications/push', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');

    Route::get('settings/export', [DataExportController::class, 'index'])->name('export.index');
    Route::post('settings/export', [DataExportController::class, 'store'])->name('export.store');
});

/*
 * The download is signed and expiring (PRD §9), which is what carries the
 * authorisation across a link somebody may open from an email.
 */
Route::get('settings/export/{export}/download', [DataExportController::class, 'download'])
    // `two-factor` belongs here too. Every other team-settings route bounced
    // an un-enrolled owner while this one handed them the whole tenant, which
    // is the exact thing the mandate exists to stop.
    ->middleware(['auth', 'verified', 'two-factor', 'team', 'signed'])
    ->name('export.download');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
