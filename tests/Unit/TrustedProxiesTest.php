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
    /*
     * `flushState()` is the framework's own and is what clears the static
     * these tests set. Symfony's `Request` statics are left alone: the
     * middleware sets them per request, and writing a guessed "default" back
     * would be a fake restore rather than a real one.
     */
    TrustProxies::flushState();
});

/**
 * Apply the configured list by running the real `AppServiceProvider::boot()`.
 *
 * Delete `$this->configureTrustedProxies();` from it and these fail, which is
 * the whole point: the first version of this file reflection-invoked the
 * method, and review showed the wiring could be deleted with every test still
 * green — CLAUDE.md's *"a test named for a promise it does not assert is worse
 * than no test"*.
 *
 * `boot()` directly rather than `register(force: true)`: re-registering drops
 * already-resolved singletons and adds a second `JobFailed` listener, none of
 * which this is about.
 *
 * And not the environment plus `refreshApplication()`, which would cover the
 * config expression in the same pass. Whether a `$_SERVER` value set mid-run
 * survives another boot depends on whether the variable is already externally
 * defined — phpdotenv's writer is immutable, so `.env` loses to a real process
 * variable and wins over one that only appeared mid-run. That makes the answer
 * differ between CI (`.env` copied from `.env.example`) and `make check` in the
 * container (`env_file: .env` puts it in the real environment), which is
 * CLAUDE.md's *"a pin that cannot pin"* — a test resting on it would be green
 * in one and red in the other. The parsing is covered separately, against a
 * fresh read of the file, where `env()` reads `$_SERVER` live and no boot
 * intervenes.
 */
function bootWithTrustedProxies(array $proxies): void
{
    config(['app.trusted_proxies' => $proxies]);

    TrustProxies::flushState();

    (new AppServiceProvider(app()))->boot();
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

    expect(schemeSeenBy(forwardedRequest('192.0.2.10')))
        ->toBeTrue('a forwarded scheme from the configured proxy was not believed — is boot() still applying the list?');
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

    expect(schemeSeenBy(forwardedRequest('198.51.100.7')))
        ->toBeFalse('a forwarded scheme was believed from an address that is not a configured proxy');
});

/*
 * Empty is the documented topology (Deployment §3) — Caddy terminates TLS in
 * the app's own container with nothing in front, so nobody is entitled to make
 * the claim. It has to fail in that direction rather than fall back to trusting
 * the immediate peer.
 */
it('trusts nobody when nothing is configured', function (): void {
    bootWithTrustedProxies([]);

    expect(schemeSeenBy(forwardedRequest('192.0.2.10')))
        ->toBeFalse('an empty list trusted somebody — the safe direction is to trust nobody');
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
 * Every spelling of "anybody" is refused, not just the wildcard.
 *
 * `TrustProxies::setTrustedProxyIpAddresses()` compares `$trustedIps === '*'`
 * against the **string**. Parsed into `['*']` it misses that branch and `*`
 * reaches Symfony as a literal address matching nothing — measured: `isSecure`
 * false, `ip` the peer's. So the value three documents called dangerous did
 * nothing at all, and somebody who set it believed their proxy was trusted.
 *
 * Refusing only `*` then made the check a spelling test, which is what round 2
 * of review caught. `REMOTE_ADDR` and `0.0.0.0/0`/`::/0` measure identically to
 * a working wildcard — `isSecure` true, `ip` taken from `X-Forwarded-For` —
 * and `REMOTE_ADDR` is what somebody reaches for once `*` throws. Each is a
 * case here because each was reachable when only `*` was named.
 */
it('refuses every spelling of anybody, not just the wildcard', function (string $value): void {
    expect(fn () => bootWithTrustedProxies([$value]))
        ->toThrow(RuntimeException::class, 'may not mean "anybody"');
})->with(['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0']);

it('refuses one bad entry in an otherwise sound list', function (): void {
    expect(fn () => bootWithTrustedProxies(['10.0.0.0/8', 'REMOTE_ADDR']))
        ->toThrow(RuntimeException::class, 'REMOTE_ADDR');
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
 * The guard on the file itself, and a narrower claim than it has carried twice.
 *
 * Round 1 of it said swapping `env()` for `config()` in that closure "passes
 * every check in this repository and silently trusts nobody, forever". False:
 * `config` is unbound that early, so it throws.
 *
 * Round 2 of it then said this test would "name the file, the function and the
 * line" for that case. Also false, and review proved it: the application is
 * constructed before any test body runs, so a `config()` call there errors
 * every test in the file at `Container.php:1145` and this assertion never
 * executes at all.
 *
 * So the honest scope is one case: a bare `env()` there boots fine and returns
 * null, the suite runs, and *this* is what says which line did it. That is
 * worth the twenty lines only because larastan's `noEnvCallsOutsideOfConfig`
 * has to stay switched on to keep covering it, and a `phpstan.neon` edit is
 * one line. Two wrong justifications for one guard is itself the lesson: state
 * what a test demonstrates, not what it feels like it protects.
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
        if (! is_array($token)) {
            continue;
        }

        /*
         * `\env(...)` is one token, not two. PHP 8 lexes a leading separator
         * into `T_NAME_FULLY_QUALIFIED` rather than `T_NS_SEPARATOR` followed
         * by `T_STRING`, so a guard watching only `T_STRING` misses it — which
         * is what round 2 of review demonstrated by inserting exactly that and
         * leaving the file green.
         */
        if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $name = strtolower(ltrim($token[1], '\\'));

        if (! in_array($name, ['env', 'config'], true)) {
            continue;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }

        /*
         * `Foo::env(` and `$x->env(` are somebody else's method and not this
         * rule's business. A fully qualified name cannot be either.
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
