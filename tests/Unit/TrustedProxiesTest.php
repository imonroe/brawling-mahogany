<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Who may claim what the request really was, and where that claim is read from.
 *
 * ## The defect this is written against
 *
 * `TRUSTED_PROXIES` arrived in `bootstrap/app.php`, read with `env()` inside
 * the `withMiddleware()` callback. That callback is registered on
 * `afterResolving(HttpKernel::class)` and fires when
 * `Application::handleRequest()` resolves the kernel — **before**
 * `$kernel->handle()` runs its bootstrappers. Both `LoadEnvironmentVariables`
 * and `LoadConfiguration` are bootstrappers, so at that point `.env` has not
 * been read at all and `config` is not bound. `env()` there answers only from
 * the real process environment; config caching does not come into it.
 *
 * larastan's `noEnvCallsOutsideOfConfig` named it and CI went red; it was
 * merged past rather than fixed (`dev` @ c6c3146). The value now lives in
 * `config/app.php` and is applied by `AppServiceProvider::boot()`, which runs
 * inside `handle()` and ahead of the middleware pipeline.
 *
 * ## Why these go through a real boot rather than calling the method
 *
 * The first version of this file reflection-invoked
 * `configureTrustedProxies()` directly. Adversarial review deleted the call to
 * it from `boot()` and **all six tests still passed** — the exact defect the
 * change exists to fix could be reintroduced under a green file with this
 * name, which is CLAUDE.md's *"a test named for a promise it does not assert
 * is worse than no test"*.
 *
 * So the list is applied by booting the real provider, and the assertion is
 * made against what a request then sees. The wiring is part of what is under
 * test, because the wiring was the bug. See `bootWithTrustedProxies()` for why
 * that is a provider boot rather than a whole-application refresh.
 */
afterEach(function (): void {
    TrustProxies::flushState();
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

/**
 * Apply the configured list the way a booting application applies it.
 *
 * `register(force: true)` re-registers the provider and, because the
 * application is already booted, boots it immediately — so this runs the real
 * `AppServiceProvider::boot()`. Delete `$this->configureTrustedProxies();`
 * from it and these fail, which is the whole point: the first version of this
 * file reflection-invoked the private method, and review showed the wiring
 * could be deleted with every test still green.
 *
 * Why not set the environment and call `refreshApplication()` instead, which
 * would cover the config expression in the same pass: `LoadEnvironmentVariables`
 * runs on every boot and **overwrites** `$_SERVER` from `.env`, which ships
 * `TRUSTED_PROXIES=` empty in `.env.example` and is copied to `.env` by CI. So
 * a value set mid-run does not survive the refresh — measured. The parsing is
 * covered separately below, against a fresh read of the file.
 */
function bootWithTrustedProxies(array $proxies): void
{
    config(['app.trusted_proxies' => $proxies]);

    TrustProxies::flushState();

    app()->register(new AppServiceProvider(app()), force: true);
}

function forwardedRequest(string $from): Request
{
    return Request::create('http://goldieflow.test/deals', 'GET', server: [
        'REMOTE_ADDR' => $from,
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ]);
}

function schemeSeenBy(Request $request): bool
{
    (new TrustProxies)->handle($request, fn (Request $passed): Request => $passed);

    return $request->isSecure();
}

it('believes a forwarded scheme from a configured proxy', function (): void {
    bootWithTrustedProxies(['192.0.2.10']);

    expect(schemeSeenBy(forwardedRequest('192.0.2.10')))->toBeTrue();
});

/*
 * The control. Without it the test above passes against a build that trusts
 * everybody, which is the direction that costs something: a forged
 * `X-Forwarded-For` defeats the per-IP throttle on password reset, and Docker
 * publishes the app's port around ufw, so the container answers from outside
 * the proxy too.
 */
it('ignores a forwarded scheme from anybody else', function (): void {
    bootWithTrustedProxies(['192.0.2.10']);

    expect(schemeSeenBy(forwardedRequest('198.51.100.7')))->toBeFalse();
});

/*
 * Empty is the documented topology (Deployment §3) — Caddy terminates TLS in
 * the app's own container with nothing in front, so nobody is entitled to make
 * the claim. It has to fail in that direction rather than fall back to trusting
 * the immediate peer.
 */
it('trusts nobody when nothing is configured', function (): void {
    bootWithTrustedProxies([]);

    expect(schemeSeenBy(forwardedRequest('192.0.2.10')))->toBeFalse();
});

it('reads a comma-separated list, trimmed, with the blanks dropped', function (): void {
    /*
     * A fresh read of the file rather than `config()`, because the value the
     * application booted with came from `.env` and this is about the
     * expression in `config/app.php`. `env()` answers from `$_SERVER` live, so
     * setting it here is enough — what it does *not* survive is another boot,
     * which is the paragraph above `bootWithTrustedProxies()`.
     */
    $previous = $_SERVER['TRUSTED_PROXIES'] ?? null;
    $_SERVER['TRUSTED_PROXIES'] = ' 10.0.0.0/8 , ,192.0.2.10, ';

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('app.php');
    } finally {
        if ($previous === null) {
            unset($_SERVER['TRUSTED_PROXIES']);
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $previous;
        }
    }

    expect($config['trusted_proxies'])->toBe(['10.0.0.0/8', '192.0.2.10']);

    // …and that list, applied, is what a request then sees.
    bootWithTrustedProxies($config['trusted_proxies']);

    expect(schemeSeenBy(forwardedRequest('10.1.2.3')))->toBeTrue();
});

/*
 * A wildcard is refused rather than ignored, and the reason is that ignoring it
 * is what the framework already does by accident.
 *
 * `TrustProxies::setTrustedProxyIpAddresses()` compares `$trustedIps === '*'`
 * against the **string**. Parsed into `['*']` it misses that branch entirely
 * and `*` reaches Symfony as a literal address, matching nothing — measured:
 * `isSecure` false, `ip` the peer's. So the value three documents call
 * dangerous does nothing at all, and somebody who sets it believes their proxy
 * is trusted while the site renders `http://` assets into an `https://` page.
 */
it('refuses a wildcard rather than silently ignoring it', function (): void {
    expect(fn () => bootWithTrustedProxies(['*']))
        ->toThrow(RuntimeException::class, 'may not be a wildcard');

    expect(fn () => bootWithTrustedProxies(['10.0.0.0/8', '**']))
        ->toThrow(RuntimeException::class, 'may not be a wildcard');
});

/*
 * The middleware has to actually be in the stack for any of the above to
 * describe a real request. It is a framework default rather than something
 * `bootstrap/app.php` asks for, which is exactly why nothing else here would
 * notice it going away.
 */
it('has the middleware in the global stack', function (): void {
    $kernel = app(HttpKernel::class);

    expect((new ReflectionProperty($kernel, 'middleware'))->getValue($kernel))
        ->toContain(TrustProxies::class);
});

/*
 * The guard on the file itself.
 *
 * Its first justification was wrong and worth recording as such: it claimed
 * that swapping `env()` for `config()` in that closure "passes every check in
 * this repository and silently trusts nobody, forever". It does not — review
 * tried it, and `config` is unbound that early, so it throws `Target class
 * [config] does not exist` on every request and takes the whole suite with it.
 *
 * What this is actually worth is smaller and still worth keeping. PHPStan's
 * larastan rule covers `env()` and would have to stay enabled to go on doing
 * it; and when the `config()` half does happen, the failure everybody sees is
 * an unbound container alias with no hint of which file caused it, at app
 * construction, in every test at once. This names the file, the function and
 * the line.
 */
it('reads no environment or config value in bootstrap/app.php', function (): void {
    $source = file_get_contents(base_path('bootstrap/app.php'));

    expect($source)->not->toBeFalse();

    /*
     * Tokenised rather than grepped: the explanation of *why* this rule exists
     * is a comment in that file naming both functions, and a substring search
     * would match the argument for the rule as a breach of it.
     */
    $tokens = array_values(array_filter(
        token_get_all((string) $source),
        static fn (array|string $token): bool => is_string($token)
            || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
    ));

    $called = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if (! in_array(strtolower($token[1]), ['env', 'config'], true)) {
            continue;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }

        /*
         * `\env(...)` is the same call with a leading separator, and reading
         * the token before is what tells the two apart from `Foo::env(`, which
         * is somebody else's method and not this rule's business.
         */
        $previous = $tokens[$index - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) {
            continue;
        }

        $called[] = $token[1].'() on line '.$token[2];
    }

    expect($called)->toBe([], implode(', ', $called).
        ' — bootstrap/app.php runs before LoadEnvironmentVariables and LoadConfiguration, so neither answers there.');
});
