<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * No display string may fall back to `APP_NAME` (issue #12, round 3).
 *
 * `APP_NAME` is slugged into the session cookie name and the cache, Redis and
 * Horizon prefixes (`config/session.php`, `config/cache.php`,
 * `config/database.php`, `config/horizon.php`), which makes it an
 * **infrastructure identifier** — and CLAUDE.md's rename note is explicit that
 * it still carries the `Brawling Mahogany` codename on purpose, because moving
 * one of those orphans a keyspace and signs everybody out.
 *
 * So a *display* default chained to it inherits a value nobody is allowed to
 * change. That is how every client-facing `From` line came to read *"Bosart
 * Group via Brawling Mahogany"* — and how `MAIL_FROM_NAME` went on doing it for
 * a round after the first fix, because `.env.example` and `config/mail.php` are
 * two places saying the same thing and only one of them was said.
 *
 * ## Why this reads files rather than rendering a message
 *
 * `phpunit.xml` pins `MAIL_FROM_NAME` so a developer's stale `.env` cannot
 * decide what the product calls itself in a test run — which is right, and
 * which also means no rendered-message test can see this defect at all. The
 * places the fallback is actually written are files, so this reads the files.
 */
it('never falls the mail display name back to the infrastructure identifier', function (): void {
    // The name on every message the product itself writes: S91's alert, a
    // template test send, a password reset.
    expect(file_get_contents(base_path('config/mail.php')))
        ->toContain("env('MAIL_FROM_NAME', env('APP_PRODUCT_NAME', 'Goldieflow'))");
});

it('keeps the product name key’s own default off APP_NAME', function (): void {
    // The key everything else reads. Its own default cannot be APP_NAME, or
    // the separation is decorative.
    expect(file_get_contents(base_path('config/app.php')))
        ->toContain("'product_name' => env('APP_PRODUCT_NAME', 'Goldieflow')");
});

it('interpolates the display keys through the product name in .env.example', function (string $key): void {
    /*
     * `.env.example` is copied verbatim by CI and by a first provisioning run,
     * so its interpolations are what a fresh environment actually gets.
     */
    expect(file_get_contents(base_path('.env.example')))
        ->toContain($key.'="${APP_PRODUCT_NAME}"');
})->with(['MAIL_FROM_NAME', 'VITE_APP_NAME']);

it('leaves APP_NAME itself at the codename', function (): void {
    // This rule is about what *reads* it. Changing it is the thing CLAUDE.md's
    // rename note forbids, and a test that drifted into demanding a rename
    // would be arguing with the note rather than enforcing it.
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('APP_NAME="Brawling Mahogany"');
});

it('has no display reader still pointed at config(app.name)', function (): void {
    /*
     * The readers, not the defaults. `app.name` is legitimate inside `config/`
     * — that is where the four prefixes derive from it — so the scan covers the
     * two trees that render for a person, and skips the file whose only mention
     * of the key is to say it is the wrong one.
     */
    $offenders = [];

    foreach ((new Finder)->files()->in([app_path(), resource_path()])
        ->name(['*.php', '*.vue', '*.ts', '*.blade.php']) as $file) {
        $contents = $file->getContents();

        if (! str_contains($contents, "config('app.name')")) {
            continue;
        }

        if (str_contains($contents, 'APP_PRODUCT_NAME')) {
            continue;
        }

        $offenders[] = $file->getRelativePathname();
    }

    expect($offenders)->toBe([]);
});
