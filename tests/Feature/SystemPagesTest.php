<?php

declare(strict_types=1);

use App\Models\User;
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
    $this->actingAs(User::factory()->create());

    $this->get('/design-system')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('DesignSystem/Gallery'));
});

it('keeps the gallery behind authentication', function (): void {
    $this->get('/design-system')->assertRedirect('/login');
});

it('renders a placeholder for every sidebar destination', function (): void {
    // The shell has to be walkable end to end for the review to mean anything.
    $this->actingAs(User::factory()->create());

    foreach (['work', 'deals', 'people', 'properties', 'calendar', 'keep-in-touch', 'templates'] as $path) {
        $this->get("/{$path}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Placeholder'));
    }
});
