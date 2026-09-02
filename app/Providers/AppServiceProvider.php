<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\PersonUserProvider;
use App\Listeners\ReportFailedJob;
use App\Models\Passkey;
use App\Models\Person;
use App\Support\Database\BlueprintMacros;
use App\Support\Help\HelpLibrary;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\Notify;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The resolved team, for the life of this request, job, or command.
         *
         * A singleton because ADR 0002 says there is exactly one answer at a
         * time and everything must read the same one — the global scope, the
         * shared Inertia props, and the policies all resolve it from here.
         */
        $this->app->singleton(TeamContext::class);

        /*
         * The manual (S92), memoised for the life of the request rather than
         * per resolution — two calls in one controller action would otherwise
         * read twenty-two files twice.
         */
        $this->app->singleton(HelpLibrary::class);

        /*
         * `scoped`, not `singleton`, and the distinction is the point (#101).
         *
         * `Notify` memoises a team's notification preferences so a workflow
         * instantiation reads them once rather than once per assigned task —
         * review measured twelve identical selects among sixty. A `singleton`
         * would carry that memo across requests in a long-lived worker, so a
         * preference changed on S78 would go on being ignored; `scoped` clears
         * it at the request or job boundary, which is exactly the lifetime the
         * memo is correct for.
         */
        $this->app->scoped(Notify::class);

        /*
         * And the audience beside it, for the same reason one layer along:
         * `AdvanceWorkflow` asks who should hear about a cleared gate **once
         * per cleared gate**, inside the advance's own transaction, and the
         * answer cannot change between two gates of one advance.
         */
        $this->app->scoped(NotificationAudience::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureMailGuardrail();
        $this->configureClientSurfaceLimits();

        BlueprintMacros::register();

        /*
         * `laravel/passkeys` defaults its user model to the literal string
         * `App\Models\User`, which this product does not have (IA §11: a
         * Person is not a User). Both halves are pointed at ours, and
         * App\Models\Passkey carries the `person_id` column the rename left.
         */
        Passkeys::useUserModel(Person::class);
        Passkeys::usePasskeyModel(Passkey::class);

        /*
         * An address is one address whatever its capitals. See
         * App\Auth\PersonUserProvider — config/auth.php names this driver.
         */
        Auth::provider(
            'people',
            fn ($app, array $config): PersonUserProvider => new PersonUserProvider(
                $app['hash'],
                $config['model'],
            ),
        );

        // PRD §9: a queue failure is alerted on within 15 minutes. The rule is
        // configured in Sentry; the report it fires on is this listener.
        Event::listen(JobFailed::class, ReportFailedJob::class);
    }

    /**
     * Who may claim, via the `X-Forwarded-*` headers, what the request really was.
     *
     * The list is `config/app.php`'s, which is where the argument for the
     * value lives. What belongs here is why applying it is not in
     * `bootstrap/app.php` beside every other middleware decision:
     * `withMiddleware()`'s callback runs on `afterResolving(HttpKernel::class)`,
     * and **both** `LoadEnvironmentVariables` and `LoadConfiguration` are
     * bootstrappers that run later, inside `$kernel->handle()`. So at that
     * point `.env` has not been read at all and `config` is not even bound —
     * `env()` answers only from the real process environment, and `config()`
     * throws `Target class [config] does not exist`.
     *
     * `TrustProxies::at()` writes a static the middleware resolves per
     * request, and provider boot runs inside `handle()` ahead of the
     * middleware pipeline, so this is in force by the time it is read.
     *
     * Empty means trust nobody, which is both the documented topology
     * (Deployment §3) and the safe direction to fail: an untrusted forwarded
     * header is ignored rather than believed.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_array($proxies) || $proxies === []) {
            return;
        }

        /*
         * A wildcard is refused rather than passed on, because passing it on
         * does nothing and looks like it does something.
         *
         * `TrustProxies::setTrustedProxyIpAddresses()` tests `$trustedIps ===
         * '*'` against the **string**. `TRUSTED_PROXIES=*` parses to `['*']`
         * here, which misses that branch and reaches
         * `setTrustedProxyIpAddressesToSpecificIps()`, where `*` is handed to
         * Symfony as a literal address and matches nothing — so the setting
         * three documents describe as dangerous is in fact inert, which is the
         * worse half of the trade: somebody sets it, believes their proxy is
         * trusted, and ships a site rendering `http://` assets into an
         * `https://` page with no error anywhere.
         *
         * Refusing at boot is `configureMailGuardrail()`'s shape one method
         * along, and for the same reason: the cheap failure gets the loud
         * check. Naming a network is the only supported answer, and the
         * documentation says so.
         */
        $wildcards = array_values(array_filter(
            $proxies,
            static fn (string $proxy): bool => in_array($proxy, ['*', '**'], true),
        ));

        if ($wildcards !== []) {
            throw new RuntimeException(
                'TRUSTED_PROXIES may not be a wildcard. Name the proxy\'s address or network instead — '.
                'a wildcard lets anyone reaching the container directly forge X-Forwarded-For. '.
                'See docs/Deployment.md §3.',
            );
        }

        TrustProxies::at($proxies);
    }

    /**
     * Every outbound message is rewritten to one address when
     * MAIL_REDIRECT_TO is set.
     *
     * This is the safety net behind the whole of Slice 3 (PRD §8.6): staging
     * **must** redirect all mail, so that no test reaches a real client. An email to the
     * wrong client cannot be recalled, which is why this lives in the framework
     * boot rather than in a mailer somewhere.
     *
     * PRD §8.6 used to pair that redirect with SES's own sandbox, which refused
     * unverified recipients at the API — outside this application, so it held
     * even when this was misconfigured. The account reached production access
     * on 2026-08-28 (#12) and that guard is gone. Note what the early return
     * below does now: an unset value **fails open**, silently, and this is the
     * only guard left that covers every message the product sends. See #196.
     */
    protected function configureMailGuardrail(): void
    {
        $redirectTo = config('mail.redirect_to');

        if (! is_string($redirectTo) || $redirectTo === '') {
            return;
        }

        if (app()->isProduction()) {
            // Production redirecting its mail would silently stop every client
            // update. Fail at boot instead of discovering it a week later.
            throw new RuntimeException(
                'MAIL_REDIRECT_TO must be empty in production. It is a staging guardrail.',
            );
        }

        Mail::alwaysTo($redirectTo);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    /**
     * The two limits the client surface carries, with keys of their own.
     *
     * ## Two `throttle:n,m` on one request is one bucket, not two
     *
     * Laravel's inline throttle keys a guest by `sha1(domain|ip)` and nothing
     * else — no route, no name — so stacking `throttle:60,1` on the group and
     * `throttle:10,1` on `s/request` gave both middlewares the **same** cache
     * key. Every page view spent the mail budget, and the request itself cost
     * two hits, so an ordinary *"my link expired, send me another"* round trip
     * was refused with a bare 429 on the third press.
     *
     * Named limiters get `throttle:<name>` prefixes, which is what separates
     * them. Written here rather than as two inline strings because the defect
     * is invisible in `routes/web.php`: two different numbers *look* like two
     * different limits.
     *
     * ## And they are deliberately different limits
     *
     * Sixty an hour for reading: a client refreshing their own page is not an
     * attack, and this is the surface PRD §3.3 says must work *"first try"*.
     * Ten for the endpoint that **sends mail**, on top of the per-address
     * limit `StatusPageController` applies — a global limit alone lets one
     * attacker spend everybody's budget, and a per-address limit alone lets a
     * script walk a list.
     */
    protected function configureClientSurfaceLimits(): void
    {
        RateLimiter::for(
            'client-surface',
            fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()),
        );

        RateLimiter::for(
            'client-link-request',
            fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()),
        );
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
