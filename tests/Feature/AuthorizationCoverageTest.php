<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * PRD §9 Authorization: *"Deny by default. Every controller action gated by a
 * policy."*
 *
 * Issue #46 asks for exactly this: *"a test enumerates controller actions and
 * fails on any without a policy."* Reading the route table rather than a
 * maintained list means a controller added in Slice 4 is covered the day it
 * lands.
 *
 * An action is gated when it calls `$this->authorize()`, when its form request
 * has an `authorize()` method, or when the route carries a `can:` middleware.
 */

/**
 * Routes this test cannot meaningfully ask about, each with a reason.
 *
 * "It doesn't have one" is not a reason — that is the case this test exists
 * to catch.
 */
const UNGATED_ROUTES = [
    // Reached without a membership by definition: the token is what
    // establishes the team (ADR 0002).
    'invitations.show',
    'invitations.accept',

    // The team switcher authorises by construction: it can only choose from
    // the teams the person already holds a live membership in, and anything
    // else is a 404.
    'teams.switch',

    // Somebody's own account, gated by `auth` rather than by a team policy —
    // they must reach it with no membership at all, which is exactly the case
    // the 2FA mandate strands them in.
    'profile.edit',
    'profile.update',
    'profile.destroy',
    'security.edit',
    'user-password.update',

    // Every `/admin` route sits behind `super-admin`, which is a stronger
    // gate than a policy: it 404s rather than 403s so the namespace does not
    // confirm itself (issue #52).
    'impersonation.destroy',
];

/**
 * @return list<RoutingRoute>
 */
function tenantRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        function (RoutingRoute $route): bool {
            $action = $route->getAction('controller');

            if (! is_string($action) || ! str_contains($action, '@')) {
                return false;
            }

            // Ours only: Fortify, Horizon, and Inertia's own controllers are
            // not this test's business.
            return str_starts_with($action, 'App\\Http\\Controllers\\');
        },
    ));
}

it('gates every controller action', function (): void {
    // A test that examines nothing passes for the wrong reason.
    expect(count(tenantRoutes()))->toBeGreaterThan(15);

    $ungated = [];

    foreach (tenantRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && in_array($name, UNGATED_ROUTES, true)) {
            continue;
        }

        [$class, $method] = explode('@', (string) $route->getAction('controller'));

        if (collect($route->gatherMiddleware())->contains(fn ($middleware): bool => is_string($middleware)
            && (str_starts_with($middleware, 'can:') || $middleware === 'super-admin'))) {
            continue;
        }

        $body = methodBody($class, $method);

        if (Str::contains($body, ['$this->authorize(', 'Gate::authorize('])) {
            continue;
        }

        // A form request that answers `authorize()` counts: it runs before the
        // action does, and a false answer is a 403.
        if (requestAuthorises($class, $method)) {
            continue;
        }

        $ungated[] = ($name ?? $route->uri()).' → '.class_basename($class).'@'.$method;
    }

    expect($ungated)->toBe(
        [],
        'Every controller action calls $this->authorize(), uses a FormRequest that authorises, or sits behind can:/super-admin.',
    );
});

/**
 * The source of one method, so the check reads the action rather than the
 * whole file — a controller with one gated action and one ungated one has to
 * fail, and reading the file would let the first hide the second.
 */
function methodBody(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();

    if ($file === false) {
        return '';
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $start = $reflection->getStartLine() - 1;
    $length = $reflection->getEndLine() - $start;

    return implode(PHP_EOL, array_slice($lines, $start, $length));
}

/** Whether any of the action's parameters is a FormRequest that authorises. */
function requestAuthorises(string $class, string $method): bool
{
    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $name = $type->getName();

        if (! class_exists($name) || ! is_subclass_of($name, Illuminate\Foundation\Http\FormRequest::class)) {
            continue;
        }

        if ((new ReflectionMethod($name, 'authorize'))->getDeclaringClass()->getName() === $name) {
            return true;
        }
    }

    return false;
}

it('keeps the exemption list honest', function (): void {
    // An exemption for a route that no longer exists reads as a deliberate
    // decision long after it stopped being one.
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter()
        ->all();

    $stale = array_values(array_diff(UNGATED_ROUTES, $names));

    expect($stale)->toBe([], 'Remove routes from UNGATED_ROUTES once they are gone.');
});

it('denies by default when a policy has no opinion', function (): void {
    // The policies have no `before` hook and no catch-all, so an action
    // nobody wrote a method for is refused rather than allowed.
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    expect($member->can('somethingNobodyImplemented', App\Models\TeamMembership::class))->toBeFalse();
});
