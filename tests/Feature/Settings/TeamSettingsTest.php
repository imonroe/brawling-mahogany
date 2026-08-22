<?php

declare(strict_types=1);

use App\Enums\DataExportState;
use App\Models\AuditEntry;
use App\Models\DataExport;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Branding\AccentContrast;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * S72 and S79 — team branding, and the team's own data (PRD §4.1 F1.2, §9).
 */
beforeEach(function (): void {
    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);
});

it('saves the branding and audits the change', function (): void {
    $this->patch('/settings/team', [
        'name' => 'Bosart Group',
        'timezone' => 'America/Denver',
        'brand_accent_color' => '#1F4E79',
        'sending_identity_name' => 'Emily Bosart',
        'sending_identity_email' => 'emily@example.test',
        'signature_block' => 'Emily Bosart · Bosart Group',
    ])->assertRedirect(route('team.edit'));

    $this->team->refresh();

    expect($this->team->name)->toBe('Bosart Group')
        ->and($this->team->brand_accent_color)->toBe('#1F4E79');

    $entry = AuditEntry::query()->where('action', 'team.updated')->sole();

    // PRD §9: no PII in logs. The sending identity is an address.
    expect(json_encode($entry->after, JSON_THROW_ON_ERROR))->not->toContain('emily@example.test');
});

it('warns about an accent nobody can read text on', function (): void {
    // Design System §15.6's open question, settled as "warn" — the client
    // status page is held to WCAG 2.1 AA (PRD §9).
    $this->patch('/settings/team', [
        'name' => $this->team->name,
        'timezone' => $this->team->timezone,
        // A mid-tone yellow-green: under 4.5:1 against both white and black.
        'brand_accent_color' => '#8A8A00',
    ]);

    $this->get('/settings/team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'accentWarning',
            fn (?string $warning) => $warning !== null && str_contains($warning, '4.5'),
        ));
});

it('says nothing about an accent that is fine', function (): void {
    $this->patch('/settings/team', [
        'name' => $this->team->name,
        'timezone' => $this->team->timezone,
        'brand_accent_color' => '#1F4E79',
    ]);

    $this->get('/settings/team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('accentWarning', null));
});

it('measures contrast the way WCAG does', function (): void {
    // Black on white is 21:1 by definition — if this drifts, every warning
    // above it is wrong too.
    expect(AccentContrast::ratio('#000000', '#FFFFFF'))->toBeGreaterThan(20.9)
        ->and(AccentContrast::ratio('#FFFFFF', '#FFFFFF'))->toBe(1.0);
});

it('refuses a colour that is not a colour', function (): void {
    $this->patch('/settings/team', [
        'name' => $this->team->name,
        'timezone' => $this->team->timezone,
        'brand_accent_color' => 'cornflower',
    ])->assertSessionHasErrors('brand_accent_color');
});

it('refuses team settings to somebody who only works here', function (): void {
    [$otherTeam, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $otherTeam);

    $this->get('/settings/team')->assertForbidden();
    $this->patch('/settings/team', ['name' => 'Nope', 'timezone' => 'UTC'])->assertForbidden();
});

it('exports the team’s own data behind a signed, expiring link', function (): void {
    app(TeamContext::class)->runFor($this->team, function (): void {
        TeamMembership::factory()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->contactOnly()->create(['first_name' => 'Claire'])->getKey(),
            'notes' => 'Prefers texts.',
        ]);
    });

    $this->post('/settings/export')->assertRedirect(route('export.index'));

    $export = DataExport::query()->sole();

    expect($export->state)->toBe(DataExportState::Ready)
        ->and($export->expires_at)->not->toBeNull();

    $payload = json_decode(Storage::get((string) $export->disk_path), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['team']['name'])->toBe($this->team->name)
        ->and(collect($payload['people'])->pluck('first_name'))->toContain('Claire')
        // Documents are a manifest, not files — decided in issue #56.
        ->and($payload['manifest'])->toHaveKey('documents');

    $url = URL::temporarySignedRoute('export.download', $export->expires_at, ['export' => $export->getKey()]);

    $this->get($url)->assertOk();
});

it('refuses an export whose window has closed', function (): void {
    $export = app(TeamContext::class)->runFor($this->team, fn () => DataExport::factory()->create([
        'team_id' => $this->team->getKey(),
        'state' => DataExportState::Ready,
        'disk_path' => 'exports/whatever.json',
        'expires_at' => now()->subMinute(),
    ]));

    // Signed for an hour, and still refused: the signature proves the link
    // was not tampered with, never that the archive is still current.
    $url = URL::temporarySignedRoute('export.download', now()->addHour(), ['export' => $export->getKey()]);

    $this->get($url)->assertForbidden();
});

it('refuses an unsigned download', function (): void {
    $export = app(TeamContext::class)->runFor($this->team, fn () => DataExport::factory()->create([
        'team_id' => $this->team->getKey(),
        'state' => DataExportState::Ready,
        'disk_path' => 'exports/whatever.json',
        'expires_at' => now()->addDay(),
    ]));

    $this->get("/settings/export/{$export->getKey()}/download")->assertForbidden();
});
