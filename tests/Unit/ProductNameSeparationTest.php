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
it('has the display names actually pinned for the run', function (): void {
    /*
     * The canary for `TestCase::setUp()`'s pin, and the reason it is a test at
     * all: the first version of that pin was two `phpunit.xml` `<env>` entries
     * that **did nothing**. PHPUnit skips an `<env>` whose name is already in
     * `getenv()`, Laravel's `Env` reads `$_SERVER` before either, and
     * `compose.yaml`'s `env_file: .env` puts a developer's whole `.env` into
     * the environment `make check` runs in — so the suite was green in CI and
     * ten tests red for anyone whose `.env` predated the split.
     *
     * A pin with no test is a hope. This fails the moment one stops holding.
     */
    expect(config()->string('app.product_name'))->toBe('Goldieflow')
        ->and(config()->string('mail.from.name'))->toBe('Goldieflow');
});

it('never falls the mail display name back to the infrastructure identifier', function (): void {
    // The name on every message the product itself writes: S91's alert, a
    // template test send, a password reset.
    expect(file_get_contents(base_path('config/mail.php')))
        ->toContain("env('MAIL_FROM_NAME', env('APP_PRODUCT_NAME', 'Goldieflow'))");
});

it('keeps both display keys off APP_NAME', function (): void {
    /*
     * `app.product_name` is what application code reads. `app.name` is what
     * **vendor** code reads — `Illuminate\Notifications`' email view,
     * `Illuminate\Mail`'s message components, Fortify's 2FA issuer — none of
     * which this application can edit. Round 2 fixed the first and left the
     * second, so the password-reset email rendered the codename four times.
     *
     * Pointing `app.name` at the product is safe because **nothing derives
     * infrastructure from it**: see the sibling test below.
     */
    $source = file_get_contents(base_path('config/app.php'));

    expect($source)->toContain("'product_name' => env('APP_PRODUCT_NAME', 'Goldieflow')")
        ->and($source)->toContain("'name' => env('APP_PRODUCT_NAME', 'Goldieflow')");
});

it('derives every infrastructure prefix from APP_NAME directly, never through the config key', function (string $file): void {
    /*
     * The load-bearing half of the line above. `config('app.name')` may be the
     * product's name **only** while the session cookie and the cache, Redis
     * and Horizon prefixes read `env('APP_NAME')` themselves — a config file
     * that started routing one of them through `config('app.name')` would
     * silently rename a keyspace and sign everybody out on deploy.
     */
    expect(file_get_contents(base_path($file)))
        ->toContain("env('APP_NAME'")
        ->and(file_get_contents(base_path($file)))->not->toContain("config('app.name')");
})->with([
    'config/session.php',
    'config/cache.php',
    'config/database.php',
    'config/horizon.php',
]);

it('renders the product’s name, not the codename, in a framework-owned email', function (): void {
    /*
     * The end-to-end proof, because the two assertions above are about source
     * text and this is about what a person receives. Fortify's password reset
     * is entirely vendor views; nothing in `app/` or `resources/` appears in
     * it, which is precisely why the source-scanning guard could not see it.
     */
    $rendered = (string) (new Illuminate\Auth\Notifications\ResetPassword('token'))
        ->toMail(App\Models\Person::factory()->make(['email' => 'x@example.test']))
        ->render();

    expect($rendered)->toContain('Goldieflow')
        ->and($rendered)->not->toContain('Brawling Mahogany');
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

it('has no display string interpolating the raw APP_NAME env var', function (): void {
    /*
     * The rule that survives, narrowed to what is actually wrong.
     *
     * An earlier version of this test banned `config('app.name')` from `app/`
     * and `resources/`, with an exemption for any file mentioning
     * `APP_PRODUCT_NAME` — which was a general licence rather than a targeted
     * one, and is moot now that both config keys resolve to the product. What
     * remains genuinely wrong is reaching past the config layer to `APP_NAME`
     * itself, which is the one value pinned to the codename.
     */
    $offenders = [];

    foreach ((new Finder)->files()->in([app_path(), resource_path()])
        ->name(['*.php', '*.vue', '*.ts', '*.blade.php']) as $file) {
        if (str_contains($file->getContents(), "env('APP_NAME'")) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
