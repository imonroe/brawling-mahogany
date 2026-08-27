<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSuperAdministrator;
use App\Http\Middleware\EnsureTeamContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactorAuthentication;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Http\Middleware\ThrottlePasswordResetRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Webhooks, outside the `web` group (#95).
         *
         * They carry no session and no CSRF token — a third party has
         * neither — so registering them here rather than in `web.php` is what
         * keeps `VerifyCsrfToken` from having an exception list, which is the
         * usual shape and the one that grows.
         *
         * Rate-limited generously rather than not at all: a bounce storm is
         * genuinely thousands of notifications a minute, and SNS retries a 429
         * with backoff, so the limit costs nothing but bounds what an
         * unauthenticated endpoint can be made to do before the signature
         * check runs.
         */
        then: function (): void {
            Route::middleware('throttle:600,1')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
         * Order matters, and this is the order.
         *
         * The impersonation guard runs first because it can change who the
         * authenticated person *is* — an expired support session reverts to
         * the administrator before anything downstream reads `auth()->user()`.
         * The team is resolved next (ADR 0002, layer 3), because the shared
         * Inertia props read the resolved team, the permissions the person
         * holds *in* it, and the impersonation banner.
         */
        $middleware->web(append: [
            ThrottlePasswordResetRequests::class,
            HandleAppearance::class,
            HandleImpersonation::class,
            ResolveCurrentTeam::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'team' => EnsureTeamContext::class,
            'two-factor' => RequireTwoFactorAuthentication::class,
            'super-admin' => EnsureSuperAdministrator::class,
        ]);

        /*
         * The team has to be resolved before route model binding, not after.
         *
         * `web(append:)` puts these at the end of the group, and Laravel's
         * middleware priority list then pulls `SubstituteBindings` forward —
         * so the binding ran *first*, resolved a team-scoped model with no
         * team established, and the global scope did exactly what ADR 0002
         * says it must: it threw. Every route that binds a team-scoped model
         * 500'd on `MissingTeamContextException` — `/people/{membership}`,
         * `/properties/{property}`, and the redirect a store lands on —
         * while the index screens beside them, which bind nothing, worked
         * (issue #156).
         *
         * The test suite could not see it. `TestCase::withTeam()` sets the
         * context in the container before the request is made, so by the time
         * the pipeline runs there is a team already, whatever order the
         * middleware are in. Only a real session-backed request goes through
         * `ResolveCurrentTeam` for the answer — which is what the three
         * ordering tests in `tests/Feature/Tenancy/TeamResolutionTest.php`
         * now do.
         *
         * Naming them in the priority list is what fixes the order, and it
         * fixes the whole chain rather than one link: impersonation decides
         * *who* the person is, `ResolveCurrentTeam` decides *which team* they
         * are standing in, `EnsureTeamContext` refuses when the answer is
         * none — and only then may a binding query a scoped table. Listed
         * from the binding backwards, so each entry is inserted ahead of the
         * one it must precede.
         */
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureTeamContext::class);
        $middleware->prependToPriorityList(EnsureTeamContext::class, ResolveCurrentTeam::class);
        $middleware->prependToPriorityList(ResolveCurrentTeam::class, HandleImpersonation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * S05 system pages. Every error a person can land on gets a page that
         * says what happened and then what to do (IA §10), in the theme of the
         * surface they were on: the client-facing variant follows IA §9 and
         * never uses an alarming word.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $pages = [
                403 => 'System/Forbidden',
                404 => 'System/NotFound',
                500 => 'System/ServerError',
                503 => 'System/Maintenance',
            ];

            $page = $pages[$response->getStatusCode()] ?? null;

            if ($page === null || $request->expectsJson() || app()->hasDebugModeEnabled()) {
                return $response;
            }

            $variant = match (true) {
                $request->is('admin', 'admin/*') => 'admin',
                $request->is('s/*') => 'client',
                default => 'tenant',
            };

            /*
             * IA §9: the client-facing variant offers a route back to a human.
             * The agent's details come from the team behind the status page
             * token, which Slice 4 resolves — until then the page renders
             * without the call button rather than with a wrong number.
             */
            return Inertia::render($page, [
                'variant' => $variant,
                'agentName' => null,
                'agentPhone' => null,
            ])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
