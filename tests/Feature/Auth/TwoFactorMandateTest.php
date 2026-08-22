<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\Person;
use App\Support\TwoFactorMandate;

/**
 * PRD §9: *"2FA available, **mandatory for Team Owner and Super
 * Administrator**."*
 *
 * That word does the work. It is not a setting those roles are encouraged to
 * enable — a Team Owner without 2FA cannot reach the application beyond the
 * enrolment screen.
 */
it('holds a Team Owner at the enrolment screen', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    $this->actingAsPerson($owner, $team);

    $this->get('/dashboard')->assertRedirect(route('security.edit'));
    $this->get('/people')->assertRedirect(route('security.edit'));
});

it('lets a Team Owner through once enrolled', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $team);

    $this->get('/dashboard')->assertOk();
});

it('leaves an ordinary Team Member alone', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $this->get('/dashboard')->assertOk();
});

it('holds a super administrator at the enrolment screen too', function (): void {
    $admin = Person::factory()->superAdministrator()->create();

    $this->actingAs($admin);

    $this->get('/admin')->assertRedirect(route('security.edit'));
});

it('does not let an un-enrolled owner run the team from settings', function (): void {
    // The allow-list was `settings/*`, which also covers team branding,
    // member management, and the data export. The mandate stopped an
    // un-enrolled owner reading their dashboard while leaving them able to
    // invite people and download the whole tenant.
    [$team, $owner] = $this->teamWithOwner();

    $this->actingAsPerson($owner, $team);

    foreach (['/settings/team', '/settings/members', '/settings/export'] as $path) {
        $this->get($path)->assertRedirect(route('security.edit'));
    }

    $this->post('/settings/export')->assertRedirect(route('security.edit'));

    expect(App\Models\DataExport::withoutTeamScope()->count())->toBe(0);
});

it('does not hand an un-enrolled owner the archive through a signed link', function (): void {
    // Every other route bounced correctly while the download — the one that
    // actually carries the tenant's data — did not, because its middleware
    // list was written separately and the mandate was left off it.
    [$team, $owner] = $this->teamWithOwner();

    $export = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $team,
        fn () => App\Models\DataExport::factory()->create([
            'team_id' => $team->getKey(),
            'state' => App\Enums\DataExportState::Ready,
            'disk_path' => 'exports/whatever.json',
            'expires_at' => now()->addDay(),
        ]),
    );

    $this->actingAsPerson($owner, $team);

    $url = Illuminate\Support\Facades\URL::temporarySignedRoute(
        'export.download',
        now()->addHour(),
        ['export' => $export->getKey()],
    );

    $this->get($url)->assertRedirect(route('security.edit'));
});

it('leaves the way out open', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    $this->actingAsPerson($owner, $team);

    // The enrolment screen itself, and the door — otherwise the mandate is a
    // trap rather than a requirement.
    $this->get('/settings/profile')->assertOk();
    $this->post('/logout')->assertRedirect();
});

it('does not count a half-finished enrolment as protection', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    // A scanned QR code and a closed tab: the secret is set, the confirmation
    // never happened. Treating that as enrolled would wave somebody through
    // with no second factor at all.
    $owner->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => null,
    ])->save();

    expect(app(TwoFactorMandate::class)->isEnrolled($owner))->toBeFalse();

    $this->actingAsPerson($owner, $team);

    $this->get('/dashboard')->assertRedirect(route('security.edit'));
});

it('follows the role rather than the team somebody is looking at', function (): void {
    // A Team Owner of one team, standing in another as an ordinary member.
    // The mandate protects the team they own wherever they happen to be.
    [$ownedTeam, $person] = $this->teamWithOwner();
    [$otherTeam] = $this->teamWithMember($person);

    expect(app(TwoFactorMandate::class)->isMandatoryFor($person))->toBeTrue();

    $this->actingAsPerson($person, $otherTeam);

    $this->get('/dashboard')->assertRedirect(route('security.edit'));

    unset($ownedTeam);
});

it('records the roles the mandate covers', function (): void {
    // The list is PRD §9's, and it is short on purpose.
    expect(SystemRole::TeamOwner->requiresTwoFactor())->toBeTrue()
        ->and(SystemRole::SuperAdministrator->requiresTwoFactor())->toBeTrue()
        ->and(SystemRole::TeamMember->requiresTwoFactor())->toBeFalse()
        ->and(SystemRole::StatusViewer->requiresTwoFactor())->toBeFalse()
        ->and(SystemRole::Contact->requiresTwoFactor())->toBeFalse();
});
