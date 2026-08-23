<?php

declare(strict_types=1);

use App\Models\Person;
use Inertia\Testing\AssertableInertia;

it('renders the welcome page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

it('renders the dashboard for a signed-in person', function (): void {
    // A team, because the tenant app needs a resolved one (ADR 0002). A
    // person without a membership is a different case, covered in
    // tests/Feature/Tenancy — and a Team Member rather than an owner,
    // because an owner without 2FA is redirected to enrolment by design.
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
});

it('keeps the dashboard behind authentication', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('reports healthy', function (): void {
    // Registered outside every middleware group, so it answers even when the
    // session, the encrypter, or the Vite manifest are broken. That is what
    // makes it a health check and also what makes it a weak smoke test — see
    // the assertion below, which the container job in CI mirrors.
    $this->get('/up')->assertOk();
});

it('renders a real Inertia page through the web stack', function (): void {
    // The marker the CI container job greps for after booting the image. If
    // Inertia ever changes it, this fails here rather than silently making
    // that check vacuous.
    $this->get('/')
        ->assertOk()
        ->assertSee('data-page', escape: false);
});
