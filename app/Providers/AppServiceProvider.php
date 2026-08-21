<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\ReportFailedJob;
use App\Support\Database\BlueprintMacros;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureMailGuardrail();

        BlueprintMacros::register();

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
