<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\Person;
use App\Support\Audit\AuditLogger;
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

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
