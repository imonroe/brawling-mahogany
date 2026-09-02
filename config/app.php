<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    /*
     * **`APP_PRODUCT_NAME`, not `APP_NAME`** — and this line is safe to change
     * for a reason worth checking rather than trusting: every infrastructure
     * derivation reads `env('APP_NAME')` **directly**, never through this key.
     * `config/session.php`, `config/cache.php`, `config/database.php` and
     * `config/horizon.php` each call `Str::slug((string) env('APP_NAME', …))`,
     * so the session cookie and the three prefixes are untouched by what this
     * resolves to.
     *
     * Which leaves this key with only *display* readers, exactly as
     * Laravel documents it above — and most of them are in vendor views this
     * application cannot edit. Fortify's password-reset email rendered the
     * pre-rename codename four times and none of the product's name, because
     * `Illuminate\Notifications`' email view and `Illuminate\Mail`'s message
     * components all read this key. Round 2 added `app.product_name` beside it
     * and left this one on `APP_NAME`, which fixed the code we own and nothing
     * we do not.
     *
     * Both keys now resolve to the same env var. `app.product_name` stays
     * because application code should say which of the two questions it is
     * asking; this one is here so a framework view asking the other one gets a
     * true answer instead of a codename.
     */
    'name' => env('APP_PRODUCT_NAME', 'Goldieflow'),

    /*
    |--------------------------------------------------------------------------
    | Product Name
    |--------------------------------------------------------------------------
    |
    | What the product is *called*, wherever a person reads it: a browser tab,
    | an invitation, the "via Goldieflow" half of a client-facing From line.
    |
    | Deliberately **not** `app.name`, and the separation is load-bearing rather
    | than tidy. `APP_NAME` is slugged into the session cookie name, the cache
    | prefix, the Redis prefix and the Horizon prefix (see config/session.php,
    | config/cache.php, config/database.php, config/horizon.php) — so it is an
    | infrastructure identifier, and CLAUDE.md's rename note is explicit that
    | those still carry the `Brawling Mahogany` codename on purpose: moving one
    | orphans a keyspace and signs every session out.
    |
    | Which left the product's own name pinned to a codename it stopped using
    | in August 2026, in the one line a client reads most carefully. A team
    | called Bosart Group was sending "Bosart Group via Brawling Mahogany" to
    | sellers. Two names doing one job each is the fix; one name doing two jobs
    | is why nobody could change it.
    |
    */

    'product_name' => env('APP_PRODUCT_NAME', 'Goldieflow'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Who may claim, via `X-Forwarded-Proto`, that the request arrived over
    | HTTPS. Empty is correct for the documented topology (Deployment §3),
    | where Caddy terminates TLS inside the app's own container with nothing
    | in front of it, so nobody is trusted to make that claim.
    |
    | Set it only when a reverse proxy terminates TLS instead. Otherwise
    | Laravel reads the scheme as `http`, writes `http://` asset URLs into an
    | `https://` page, and the browser blocks them as mixed content. Give the
    | proxy's address or network (comma-separated), never `*`: Docker
    | publishes the app's port around ufw, so the container answers from
    | outside the proxy too, and a wildcard lets anyone forge
    | `X-Forwarded-For` and defeat the per-IP throttle on password reset.
    |
    | This is read by App\Providers\AppServiceProvider, and it lives here
    | rather than in bootstrap/app.php because the `withMiddleware` callback
    | runs on `afterResolving(HttpKernel::class)` — before `LoadConfiguration`
    | — so neither `config()` nor a cached-config `env()` can be read there.
    |
    */

    'trusted_proxies' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))),
        static fn (string $proxy): bool => $proxy !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
