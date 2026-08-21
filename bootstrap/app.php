<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
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
