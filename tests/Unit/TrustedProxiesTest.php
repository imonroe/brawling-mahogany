<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Who may claim the request was HTTPS, and where that claim is read from.
 *
 * ## The defect this is written against
 *
 * `TRUSTED_PROXIES` arrived in `bootstrap/app.php`, read with `env()` inside
 * the `withMiddleware()` callback. That callback is registered on
 * `afterResolving(HttpKernel::class)` and fires when
 * `Application::handleRequest()` resolves the kernel — **before**
 * `$kernel->handle()` runs `LoadConfiguration`. So at that point `config()` is
 * empty, and `env()` answers only from the process environment: null on any
 * box where `config:cache` has run and the variable arrives in a `.env` file.
 * `docker/entrypoint.sh` caches the config on every container start, which is
 * every deployed box this product has.
 *
 * larastan's `noEnvCallsOutsideOfConfig` named it and CI went red; it was
 * merged past rather than fixed (`dev` @ c6c3146). The value now lives in
 * `config/app.php` and is applied by `AppServiceProvider::boot()`, which runs
 * inside `handle()` and ahead of the middleware pipeline.
 *
 * ## Why a guard against `config()` too, and not only `env()`
 *
 * PHPStan catches the `env()` half repo-wide. It cannot catch the obvious
 * "fix" — swapping `env()` for `config()` in the same closure — which passes
 * every check in this repository and silently trusts nobody, forever. That is
 * the failure mode worth a test: it looks handled.
 */
beforeEach(function (): void {
    forgetTrustedProxies();
});

afterEach(function (): void {
    forgetTrustedProxies();
    Request::setTrustedProxies([], 0);
});

/**
 * `TrustProxies::at()` writes a static that outlives a test, so the negative
 * control below would otherwise be answering a previous test's configuration.
 */
function forgetTrustedProxies(): void
{
    $property = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
    $property->setValue(null, null);
}

function bootTrustedProxies(): void
{
    (new ReflectionMethod(AppServiceProvider::class, 'configureTrustedProxies'))
        ->invoke(new AppServiceProvider(app()));
}

function forwardedRequest(string $from): Request
{
    return Request::create('http://goldieflow.test/deals', 'GET', server: [
        'REMOTE_ADDR' => $from,
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
}

it('believes a forwarded scheme from a configured proxy', function (): void {
    config(['app.trusted_proxies' => ['192.0.2.10']]);

    bootTrustedProxies();

    $request = forwardedRequest('192.0.2.10');

    (new TrustProxies)->handle($request, fn (Request $passed): Request => $passed);

    expect($request->isSecure())->toBeTrue();
});

/*
 * The control. Without it the test above passes against a build that trusts
 * everybody, which is the direction that costs something: a forged
 * `X-Forwarded-For` defeats the per-IP throttle on password reset, and Docker
 * publishes the app's port around ufw, so the container answers from outside
 * the proxy too.
 */
it('ignores a forwarded scheme from anybody else', function (): void {
    config(['app.trusted_proxies' => ['192.0.2.10']]);

    bootTrustedProxies();

    $request = forwardedRequest('198.51.100.7');

    (new TrustProxies)->handle($request, fn (Request $passed): Request => $passed);

    expect($request->isSecure())->toBeFalse();
});

/*
 * Empty is the documented topology (Deployment §3) — Caddy terminates TLS in
 * the app's own container with nothing in front, so nobody is entitled to make
 * the claim. It has to fail in that direction rather than fall back to trusting
 * the immediate peer.
 */
it('trusts nobody when nothing is configured', function (): void {
    config(['app.trusted_proxies' => []]);

    bootTrustedProxies();

    $request = forwardedRequest('192.0.2.10');

    (new TrustProxies)->handle($request, fn (Request $passed): Request => $passed);

    expect($request->isSecure())->toBeFalse();
});

it('reads a comma-separated list, trimmed, with the blanks dropped', function (): void {
    $before = $_SERVER['TRUSTED_PROXIES'] ?? null;
    $_SERVER['TRUSTED_PROXIES'] = ' 10.0.0.0/8 , ,192.0.2.10, ';

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('app.php');
    } finally {
        if ($before === null) {
            unset($_SERVER['TRUSTED_PROXIES']);
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $before;
        }
    }

    expect($config['trusted_proxies'])->toBe(['10.0.0.0/8', '192.0.2.10']);
});

/*
 * The middleware has to actually be in the stack for any of the above to
 * describe a real request. It is a framework default rather than something
 * `bootstrap/app.php` asks for, which is exactly why nothing else here would
 * notice it going away.
 */
it('has the middleware in the global stack', function (): void {
    $middleware = (new ReflectionProperty(app(HttpKernel::class), 'middleware'))
        ->getValue(app(HttpKernel::class));

    expect($middleware)->toContain(TrustProxies::class);
});

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

        if (($tokens[$index + 1] ?? null) === '(') {
            $called[] = $token[1].'() on line '.$token[2];
        }
    }

    expect($called)->toBe([], implode(', ', $called).
        ' — bootstrap/app.php runs before LoadConfiguration, so neither answers there.');
});
