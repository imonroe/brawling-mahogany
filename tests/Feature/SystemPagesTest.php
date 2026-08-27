<?php

declare(strict_types=1);

use App\Models\Person;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * S05. Every error a person can land on renders a page that says what
 * happened and then what to do — in the theme of the surface they were on.
 */
beforeEach(function (): void {
    // The system pages replace the debug page, so they only render with debug
    // off. That is deliberate: a developer wants the stack trace.
    config()->set('app.debug', false);
});

it('renders a page for every status the handler maps', function (string $status, string $component): void {
    // 403, 500, and 503 all reach a person. The 503 is the interesting one:
    // maintenance mode is raised by PreventRequestsDuringMaintenance, before
    // the session starts, while the Inertia middleware reaches for the user.
    Route::get('/system-probe', fn () => abort((int) $status))->middleware('web');

    $this->get('/system-probe')
        ->assertStatus((int) $status)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component($component)
            ->where('variant', 'tenant'));
})->with([
    ['403', 'System/Forbidden'],
    ['500', 'System/ServerError'],
    ['503', 'System/Maintenance'],
]);

it('renders the tenant 404 for an unknown page', function (): void {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('System/NotFound')
            ->where('variant', 'tenant'));
});

it('renders the admin variant under the admin namespace', function (): void {
    // IA §5.5: the admin surface is visually distinct so nobody confuses it
    // with the tenant app — including when it is showing an error.
    $this->get('/admin/nothing-here')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('System/NotFound')
            ->where('variant', 'admin'));
});

it('sends an unknown status page token to S64 rather than to a 404', function (): void {
    /*
     * Before Slice 4 there was no `/s/{token}` route, so an unknown token fell
     * through to the client-variant 404 — which was the right answer while the
     * screen it should reach did not exist.
     *
     * It does now (#110), and S64 is a better answer than any 404 can be: it
     * says *which* of expired, already used, or revoked, and it offers a way
     * to ask for a new link knowing nothing but an email address. The 404's
     * client variant is still asserted below, on a client-surface path that
     * genuinely has no route.
     */
    $this->get('/s/'.str_repeat('z', 43))
        ->assertRedirect('/s/expired?reason=expired');

    $this->get('/s/expired')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Status/Expired')
            ->where('reason', 'expired'));
});

it('renders the client variant of a 404 on the client surface', function (): void {
    // IA §9: no alarming words, and a route back to a human.
    $this->get('/s/'.str_repeat('z', 43).'/nothing-here')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('System/NotFound')
            ->where('variant', 'client'));
});

it('leaves the debug page alone when debug is on', function (): void {
    config()->set('app.debug', true);

    $this->get('/no-such-page')->assertNotFound();
});

it('renders the component gallery for review', function (): void {
    // The gallery is what the AppLayout review with Heather is run against
    // (Design System §13.3), so it breaking is not a cosmetic problem.
    $this->actingAs(Person::factory()->create());

    $this->get('/design-system')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('DesignSystem/Gallery'));
});

it('keeps the gallery behind authentication', function (): void {
    $this->get('/design-system')->assertRedirect('/login');
});

it('renders a placeholder for every sidebar destination', function (): void {
    // The shell has to be walkable end to end for the review to mean anything.
    // People is no longer a placeholder — Slice 1 built it (S30) — and neither
    // are Properties (S35, #61) or Deals (S13's shell, #74).
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    // `work` left this list with S11 (#80) — it is a real screen now,
    // `templates` left it with S39–S43 (#84–#86), asserted below against an
    // owner because `templates.manage` is a Team Owner permission, and
    // `calendar` left it with S57 (#105).
    foreach (['keep-in-touch'] as $path) {
        $this->get("/{$path}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Placeholder'));
    }

    /*
     * `/deals` is a placeholder with one working control rather than the
     * generic one: PRD §5.2 step 1 is "Heather clicks New Deal", and the
     * wizard behind it is real (#74). A nav item pointing at a screen with no
     * way into the thing it is about would make S14 unreachable except by
     * typing the URL.
     */
    $built = [
        '/people' => 'People/Index',
        '/properties' => 'Properties/Index',
        '/deals' => 'Deals/Index',
        '/calendar' => 'Calendar/Index',
        // S59 (#107). Its own sidebar row beside Calendar, because *"what is
        // this week's exposure"* is asked from a standing start.
        '/dates' => 'Dates/Index',
    ];

    foreach ($built as $path => $component) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
    }

    /*
     * Templates and roles are Team Owner screens — IA §7's separation showing
     * up in the seeded roles, and the reason they are asserted from a second
     * account rather than added to the loop above. A Team Member seeing them
     * would be the finding, so both refusals are asserted too.
     */
    $this->get('/templates')->assertForbidden();
    $this->get('/settings/roles')->assertForbidden();

    [$ownerTeam, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $ownerTeam);

    $this->get('/templates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Templates/Index'));

    $this->get('/settings/roles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Roles'));
});
