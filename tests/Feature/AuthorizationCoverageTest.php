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

    // Same situation, no token (ADR 0003). There is no team to hold a policy
    // — that *is* the case — and the authorisation is that the signed-in
    // account's own address is the invited one, which
    // `PendingInvitations::find()` is the only thing that performs. A miss is
    // a 404, so nothing can be probed by walking ids either.
    'invitations.claim',

    // The team switcher authorises by construction: it can only choose from
    // the teams the person already holds a live membership in, and anything
    // else is a 404.
    'teams.switch',

    /*
     * S08 and S78 (#101). Authorised by construction, like the switcher above.
     *
     * Every query on all four is `where('person_id', $me)` — the rows are ones
     * addressed to the person asking, and the write is an `update()` over that
     * same predicate, so another person's id matches nothing rather than being
     * refused. `NotificationScreensTest` asserts both halves: a stranger's
     * notifications are absent from the panel, and marking one read leaves it
     * unread.
     *
     * There is also no permission that could gate them. Everybody in a team
     * hears about their own work and chooses how — a `notifications.view`
     * would be a permission an owner could take away, which is not a thing
     * this product should be able to express.
     *
     * S08 deliberately reads **across** teams (issue #101: switching teams
     * must not hide a notification), so a team-scoped policy would be the
     * wrong shape as well as an unnecessary one.
     */
    'notifications.index',
    'notifications.read',
    /*
     * `notifications.open` is the same predicate one step further on: the row
     * is found among **this person's**, a stranger's id is a 404 rather than a
     * 403, and the team switch it performs is refused unless `activeTeams()`
     * still contains the team — so following a link cannot re-establish a
     * membership that was revoked.
     */
    'notifications.open',
    'notification-preferences.edit',
    'notification-preferences.update',

    /*
     * S55 (#103), and the same argument one table along.
     *
     * Both actions are keyed on `$request->user()`, so a subscription can
     * only ever be attached to — or removed from — the person asking; the
     * `destroy` rule scopes its `exists` check to their own rows, so another
     * person's endpoint is a validation error rather than a deletion. There
     * is no permission that could gate them either: everybody decides whether
     * their own phone buzzes, and a `push.manage` would be a permission an
     * owner could take away.
     *
     * A policy would also be the wrong **shape**: a push subscription carries
     * no `team_id` (it belongs to a browser, not a tenancy — see the
     * migration), and every policy in this product resolves a team.
     */
    'push-subscriptions.store',
    'push-subscriptions.destroy',

    /*
     * The manual (S92, #170), which asks only that somebody is signed in —
     * and is outside `verified`, `two-factor` and `team` as well, so an owner
     * held at 2FA enrolment can read the article about 2FA enrolment.
     * `HelpTest` asserts that middleware stack directly.
     *
     * A help section gated on `deals.view` cannot explain what a deal is to
     * the person deciding whether to ask for that permission, and a Contact
     * given a login has as much reason to read *Signing in* as an owner does.
     *
     * There is also nothing to authorize: the content is repository files,
     * identical on every install, carrying no customer data and no `team_id`.
     * `HelpLibrary` reads `resources/help` and nothing else — a slug that
     * names no file is a 404 rather than a probe.
     */
    'help.index',
    'help.show',

    /*
     * The client status page (#110, #111), and the same situation the
     * invitation routes at the top of this list are in — one step further out.
     *
     * There is no `$request->user()` at all. A client has no account, no
     * membership and no session, which is the whole of PRD §3.3's *"must work
     * on a phone, first try, no password"*. **The token is the
     * authorisation**, and it establishes the tenant as well: ADR 0002's
     * stated exception.
     *
     * A policy could not run — there is nobody to ask it about — and a
     * permission would be a permission held by nobody. What takes their place
     * is asserted rather than assumed, in `StatusPageTest`: an expired token,
     * a spent token and a revoked token each land on S64; a token from another
     * team's deal reaches nothing; and the credential is 256 bits of
     * `random_bytes` on a unique column, so an equality match can only ever
     * find the one row it names.
     *
     * `status.request` is the one that is genuinely open, and deliberately: it
     * takes an email address and nothing else, because #110 requires that a
     * client be able to ask for a new link *"knowing nothing but their email
     * address"*. It is rate-limited twice over — globally on the route, and
     * per address in the controller — it answers identically whether or not
     * the address is one we know, and it can only re-issue access somebody
     * already had. `status.documents.show` is **not** on this list: it is the
     * one that hands over bytes, and it narrows by hand against the link's own
     * team, the deal that link names, and `client_visible`.
     */
    'status.show',
    'status.documents',
    'status.expired',
    'status.request',

    /*
     * The `.ics` feed (#108), and the same situation one surface along: the
     * reader is a calendar client, not a person. There is no session to
     * authorise and no policy that could be asked — F8.3 chose read-only iCal
     * over two-way sync precisely because it *"works everywhere, no OAuth"*.
     *
     * The token is the authorisation and it establishes the tenant.
     * `CalendarFeedsTest` asserts what stands in for a policy: a revoked token
     * is a 404 rather than a 403 (a calendar client cannot read a refusal, and
     * the difference is what would confirm a token had once been real), an
     * unknown one is the same 404, the document a live token produces is
     * scoped to that feed's own team and — for a per-deal feed — to that deal,
     * and **the permission is asked here too** (#194): the token stops
     * resolving when its person leaves the team or stops holding
     * `calendar.view`, asked of the membership in the feed's own team, so this
     * route is not a way around `EventPolicy::viewAny()`.
     *
     * Its two writing siblings, `calendar.feeds.store` and `.destroy`, are
     * **not** on this list: they are the team app and they authorise.
     */
    'calendar.feeds.show',

    // Somebody's own account, gated by `auth` rather than by a team policy —
    // they must reach it with no membership at all, which is exactly the case
    // the 2FA mandate strands them in.
    'profile.edit',
    'profile.update',
    'profile.destroy',
    'security.edit',
    'user-password.update',

    // The one `/admin` route deliberately *not* behind `super-admin` — an
    // impersonating session holds the impersonated person's permissions, so
    // requiring the super-admin gate to stop impersonating would trap them
    // in it. Safe without one because there is nothing to authorize:
    // `Impersonation::stop()` returns immediately when the session key is
    // absent, so a caller who is not impersonating gets a no-op.
    'impersonation.destroy',

    /*
     * Where everybody lands (#79). Five things redirect here — impersonation
     * in and out, the team switcher, and both invitation paths — so a 403
     * strands somebody the moment they sign in, and somebody with a team and
     * no `deals.view` has a shell and simply nothing to be shown in it.
     *
     * Not ungated in substance: `DashboardController` asks `viewAny` on
     * `Deal` and hands back the empty state when the answer is no, and
     * `TeamDashboard` is never called at all in that case. What it does not
     * do is *throw*, which is the only thing this test can see.
     */
    'dashboard',
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
