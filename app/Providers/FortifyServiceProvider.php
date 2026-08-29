<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordResetLinkRequested;
use App\Models\Person;
use App\Support\Admin\Impersonation;
use App\Support\Audit\AuditLogger;
use App\Support\Push\PushSubscriptionRegistry;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
        $this->configureAuditing();
    }

    /**
     * A person with no password never authenticates (PRD F2.1, issue #43).
     *
     * Most people in this product are clients, vendors, and opposing agents
     * who have no credentials at all. Laravel's default provider already
     * refuses an empty hash, but the failure mode if that ever changed is
     * catastrophic and silent — so the rule is stated here, once, on the path
     * every credential check takes.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?Person {
            $person = Person::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower((string) $request->input(Fortify::username()))])
                ->first();

            if (! $person instanceof Person || ! $person->hasCredentials()) {
                return null;
            }

            return Hash::check((string) $request->input('password'), (string) $person->password)
                ? $person
                : null;
        });
    }

    /**
     * PRD §9: the append-only audit log covers **authentication**.
     *
     * No address is written — the actor id identifies who signed in, and a
     * failed attempt records only that one happened from an address the log
     * does not keep (PRD §9: no PII in logs, ever).
     */
    private function configureAuditing(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $person = $event->user;

            app(AuditLogger::class)->record(
                action: 'auth.signed_in',
                auditableType: Person::class,
                auditableId: $person instanceof Person ? $person->getKey() : null,
                actorPersonId: $person instanceof Person ? $person->getKey() : null,
            );
        });

        Event::listen(Logout::class, function (Logout $event): void {
            $person = $event->user;

            if (! $person instanceof Person) {
                return;
            }

            app(AuditLogger::class)->record(
                action: 'auth.signed_out',
                auditableType: Person::class,
                auditableId: $person->getKey(),
                actorPersonId: $person->getKey(),
            );

            /*
             * **Every push subscription this person holds, on the way out**
             * (#103).
             *
             * Not only this browser's, and that is the point. A subscription
             * survives a sign-out on its own — it belongs to the browser, not
             * to the session — so without this a phone somebody handed back,
             * sold, or signed out of at an open house goes on receiving *"a
             * task was assigned to you"* on its lock screen indefinitely, with
             * no session anywhere to notice.
             *
             * The cost is that signing out on a laptop also stops the phone,
             * and it is the right way round: re-subscribing is one press on
             * S55, and the failure it prevents is a device nobody controls
             * showing a stranger which properties are in play.
             */
            /*
             * **Not while impersonating.** `$event->user` is then the
             * *customer*, so an operator ending an S84 support session by
             * signing out would delete every push device that customer owns
             * — a support action destroying customer state, silently, and
             * with no way to put it back except each of their people
             * re-enabling push on each of their phones.
             *
             * The same asymmetry `PushSubscriptionController::store()` guards
             * from the other end, and it is worth stating twice because the
             * two are reached by completely different code.
             */
            /*
             * `request()` rather than a captured one: the event carries no
             * request, and this fires inside the sign-out request itself —
             * before Fortify invalidates the session, so the marker is still
             * there to read.
             */
            if (Impersonation::isActive(request())) {
                return;
            }

            app(PushSubscriptionRegistry::class)->forgetFor($person);
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $person = $event->user;

            app(AuditLogger::class)->record(
                action: 'auth.failed',
                auditableType: Person::class,
                auditableId: $person instanceof Person ? $person->getKey() : null,
                actorPersonId: null,
            );
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // The forgot-password screen must answer identically whether or not
        // the address exists (issue #43).
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            fn ($app, array $parameters): PasswordResetLinkRequested => new PasswordResetLinkRequested(
                (string) ($parameters['status'] ?? ''),
            ),
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('Auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('Auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('Auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            /*
             * IA §10: errors say what happened, then what to do. A bare 429
             * says neither — issue #43 is explicit that *"a rate-limited login
             * says how long to wait, not 'too many attempts'."*
             */
            return Limit::perMinute(5)->by($throttleKey)->response(
                fn (Request $request, array $headers) => back()
                    ->withInput($request->only(Fortify::username()))
                    ->withErrors([
                        Fortify::username() => __(
                            'Too many sign-in attempts. Wait :seconds seconds and try again.',
                            ['seconds' => (int) ($headers['Retry-After'] ?? 60)],
                        ),
                    ]),
            );
        });

        /*
         * The one people forget (issue #43).
         *
         * Laravel's password broker already throttles repeat requests for the
         * *same* address, which covers the impatient customer. What it does
         * not cover is somebody walking a list of addresses to find out which
         * ones exist, so this is keyed by origin rather than by address.
         */
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
