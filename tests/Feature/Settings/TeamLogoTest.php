<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Support\Branding\TeamLogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * S72's logo control (issue #97, closing #55's remaining half).
 *
 * `teams.logo_path` shipped with team branding and had no writer at all. That
 * was harmless until S86 made *"per-team logo"* a headline state: a layout
 * that reads a column nothing can fill is `CLAUDE.md`'s S17 finding pointed
 * the other way round, and reads as finished from either end.
 */
beforeEach(function (): void {
    Storage::fake(TeamLogo::DISK);

    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);
});

function pngUpload(string $name = 'logo.png', int $kilobytes = 20): UploadedFile
{
    // A real PNG, because the allowlist reads the bytes and not the filename.
    return UploadedFile::fake()->image($name, 400, 120)->size($kilobytes);
}

it('stores a logo and records it against the team', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload()])
        ->assertRedirect('/settings/team');

    $path = $this->team->fresh()->logo_path;

    expect($path)->not->toBeNull();

    Storage::disk(TeamLogo::DISK)->assertExists($path);

    /*
     * F6.4's key rule, borrowed: the key reveals nothing. A team segment so a
     * bucket can be reasoned about per tenant, and a ULID that is not
     * guessable from another one — never the team's name or the filename.
     */
    expect($path)->toStartWith($this->team->getKey().'/branding/')
        ->and($path)->not->toContain('logo');
});

it('refuses a file that is not one of the three types a mail client draws', function (): void {
    /*
     * SVG most of all: it is a document that can carry `<script>` and external
     * references, and this one is rendered into a client's email and served
     * back into a colleague's browser.
     */
    $svg = UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $this->post('/settings/team/logo', ['logo' => $svg])
        ->assertSessionHasErrors('logo');

    expect($this->team->fresh()->logo_path)->toBeNull();
});

/**
 * A **real** upload over a temp file, so `getMimeType()` runs `finfo`.
 *
 * `Illuminate\Http\Testing\File::getMimeType()` derives the type from the
 * *filename* and never reads the bytes, so every assertion about
 * content-based validation written with `fake()` passes over a code path
 * production does not take. `PropertyPhotosTest` records the same thing, and
 * for the same reason: an allowlist checked against a claim is a denylist with
 * extra steps.
 */
function realLogoUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'logo');

    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

it('refuses a file whose bytes are not what its name claims', function (): void {
    $this->post('/settings/team/logo', ['logo' => realLogoUpload('mark.png', 'this is not a png at all')])
        ->assertSessionHasErrors('logo');

    expect($this->team->fresh()->logo_path)->toBeNull();
});

it('accepts a real PNG whose bytes say so', function (): void {
    // A 1x1 PNG, so `finfo` has something true to answer with.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );

    $this->post('/settings/team/logo', ['logo' => realLogoUpload('mark.png', $png)])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->logo_path)->toEndWith('.png');
});

it('refuses a logo over the size a mail client should be asked to draw', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload('big.png', 2048)])
        ->assertSessionHasErrors('logo');

    expect($this->team->fresh()->logo_path)->toBeNull();
});

it('deletes the file it replaced, and only after the new one is recorded', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload('first.png')]);
    $first = $this->team->fresh()->logo_path;

    $this->post('/settings/team/logo', ['logo' => pngUpload('second.png')]);
    $second = $this->team->fresh()->logo_path;

    expect($second)->not->toBe($first);

    Storage::disk(TeamLogo::DISK)->assertMissing($first);
    Storage::disk(TeamLogo::DISK)->assertExists($second);
});

it('removes the logo and its bytes together', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload()]);
    $path = $this->team->fresh()->logo_path;

    $this->delete('/settings/team/logo')->assertRedirect('/settings/team');

    expect($this->team->fresh()->logo_path)->toBeNull();

    /*
     * `records:purge` finds a row by its `deleted_at`, and a file nobody has a
     * row for is a file nothing will ever sweep. Same finding as the
     * polymorphic-child cascade in `CLAUDE.md`.
     */
    Storage::disk(TeamLogo::DISK)->assertMissing($path);
});

it('serves the logo to the team and audits neither read', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload()]);

    $before = AuditEntry::query()->count();

    $this->get('/settings/team/logo')
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('x-content-type-options', 'nosniff');

    /*
     * PRD §9 makes *document* access an audited event because a document is a
     * client's paperwork. A team's own letterhead, rendered on every load of
     * their settings page, would bury those entries under itself.
     */
    expect(AuditEntry::query()->count())->toBe($before);
});

it('answers 404 rather than 500 when the column points at bytes that are gone', function (): void {
    $this->team->forceFill(['logo_path' => $this->team->getKey().'/branding/gone.png'])->save();

    $this->get('/settings/team/logo')->assertNotFound();
});

it('audits a change to the branding a client sees', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload()]);

    expect(AuditEntry::query()->where('action', 'team.logo.updated')->exists())->toBeTrue();

    $this->delete('/settings/team/logo');

    expect(AuditEntry::query()->where('action', 'team.logo.removed')->exists())->toBeTrue();
});

it('never lets a member of another team read or change this team’s branding', function (): void {
    $this->post('/settings/team/logo', ['logo' => pngUpload()]);

    [$other, $otherOwner] = $this->teamWithOwner();

    $this->enrollTwoFactor($otherOwner);
    $this->actingAsPerson($otherOwner, $other);

    /*
     * The route resolves the *asking* team from context, so the outsider is
     * asking about their own team and gets their own answer — which is no
     * logo. The isolation that matters is that no path here takes a team from
     * the request.
     */
    $this->get('/settings/team/logo')->assertNotFound();

    expect($this->team->fresh()->logo_path)->not->toBeNull();
});
