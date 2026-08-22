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

it('renders the client variant for an expired status page link', function (): void {
    // IA §9: no alarming words, and a route back to a human.
    $this->get('/s/expired-token')
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
    // is Properties, which Slice 2 built (S35, issue #61).
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    foreach (['work', 'deals', 'calendar', 'keep-in-touch', 'templates'] as $path) {
        $this->get("/{$path}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Placeholder'));
    }

    $built = [
        '/people' => 'People/Index',
        '/properties' => 'Properties/Index',
    ];

    foreach ($built as $path => $component) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
    }
});
