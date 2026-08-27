<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\PersonUserProvider;
use App\Listeners\ReportFailedJob;
use App\Models\Passkey;
use App\Models\Person;
use App\Support\Database\BlueprintMacros;
use App\Support\Help\HelpLibrary;
use App\Support\Notifications\Notify;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMailGuardrail();

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
     * Every outbound message is rewritten to one address when
     * MAIL_REDIRECT_TO is set.
     *
     * This is the safety net behind the whole of Slice 3 (PRD §8.6): staging
     * runs SES in sandbox mode with mail redirected, so no test ever reaches a
     * real client. An email to the wrong client cannot be recalled, which is
     * why this lives in the framework boot rather than in a mailer somewhere.
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
