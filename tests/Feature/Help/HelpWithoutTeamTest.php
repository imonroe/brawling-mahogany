<?php

declare(strict_types=1);

use App\Models\Person;
use App\Support\Tenancy\TeamContext;

/**
 * The manual, read by somebody who is signed in and in no team (S92, #170).
 *
 * **Its own file, with no `beforeEach`, and that is the whole point.** Inside
 * `HelpTest` the case cannot be asserted honestly: `TeamContext` is an
 * in-memory singleton, so signing a teamless person in after the suite's
 * `beforeEach` has already resolved a team asserts against harness state
 * rather than against the product. A first attempt did exactly that and passed
 * for the wrong reason. Here nothing has resolved a team, which the assertion
 * below states rather than assumes.
 *
 * The case is real: `team` is one of the three middlewares `/help` was moved
 * out from under, because somebody invited and not yet in a team, or whose
 * only membership was revoked, is at one of the moments a manual is worth most.
 */
it('renders for somebody who is signed in and in no team', function (): void {
    $person = Person::factory()->create();

    $this->actingAs($person);

    // Nothing has resolved a team, so the singleton cannot be answering for us.
    expect(app(TeamContext::class)->get())->toBeNull();

    $this->get('/help')->assertOk();
    $this->get('/help/signing-in')->assertOk();

    // The control: without it, a pass here could be the `team` middleware
    // quietly not applying to anybody in this test process.
    $this->get('/dashboard')->assertRedirect('/no-team');

    expect(app(TeamContext::class)->get())->toBeNull();
});
