<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('renders the welcome page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

it('renders the dashboard for a signed-in person', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
});

it('keeps the dashboard behind authentication', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('reports healthy', function (): void {
    $this->get('/up')->assertOk();
});
