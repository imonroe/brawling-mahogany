<?php

declare(strict_types=1);

use App\Models\Person;
use Illuminate\Support\Facades\Hash;

/**
 * PRD §4.2 F2.1: *"One record per human, login credentials optional."*
 *
 * Issue #43 turns that into a requirement with teeth: *"a test asserts a
 * person with a null password cannot authenticate by any path."* Most people
 * in this product — clients, vendors, opposing agents — have no credentials
 * at all, and a null password that authenticated would hand an account to
 * every one of them.
 */
it('refuses to sign in a person with no password', function (): void {
    $person = Person::factory()->contactOnly()->create(['email' => 'claire@example.test']);

    expect($person->password)->toBeNull();

    // Every shape somebody might send: empty, absent, and the literal string
    // a null column stringifies to.
    foreach (['', 'password', 'null'] as $attempt) {
        $this->post('/login', ['email' => $person->email, 'password' => $attempt])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }
});

it('refuses a password reset to a credential-less person’s address without creating one', function (): void {
    $person = Person::factory()->contactOnly()->create(['email' => 'claire@example.test']);

    // A reset request is allowed to look identical either way — the screen
    // must not disclose whether an address exists (issue #43). What must not
    // happen is a password appearing where there was none.
    $this->post('/forgot-password', ['email' => $person->email]);

    expect($person->fresh()->password)->toBeNull();
});

it('signs in a person who does have credentials', function (): void {
    $person = Person::factory()->create([
        'email' => 'emily@example.test',
        'password' => Hash::make('a-long-enough-password'),
    ]);

    $this->post('/login', ['email' => $person->email, 'password' => 'a-long-enough-password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($person);
});

it('matches an address regardless of how it was typed', function (): void {
    $person = Person::factory()->create([
        'email' => 'emily@example.test',
        'password' => Hash::make('a-long-enough-password'),
    ]);

    $this->post('/login', ['email' => 'Emily@Example.TEST', 'password' => 'a-long-enough-password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($person);
});

it('rate limits sign-in attempts and says how long to wait', function (): void {
    $person = Person::factory()->create(['email' => 'emily@example.test']);

    // PRD §9 rate-limits login. Six attempts against a five-per-minute limit
    // is the first one refused for the limit rather than the password.
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => $person->email, 'password' => 'wrong-password']);
    }

    $this->post('/login', ['email' => $person->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    // IA §10: say what happened, then what to do. "Too many attempts" alone
    // leaves somebody clicking the button again.
    expect(session('errors')->first('email'))->toContain('seconds');
});

it('rate limits a walk through a list of addresses', function (): void {
    // Unmetered, the forgot-password form is a way to ask the product which
    // addresses exist, several times a second. Laravel's broker throttles a
    // repeat of the *same* address; this is the other half.
    foreach (range(1, 5) as $index) {
        $this->post('/forgot-password', ['email' => "guess{$index}@example.test"]);
    }

    $this->post('/forgot-password', ['email' => 'guess6@example.test'])
        ->assertStatus(429);
});

it('does not disclose whether an address exists', function (): void {
    // Issue #43: "Do not disclose whether an email address exists on the
    // forgot-password screen. The confirmation is identical either way."
    Person::factory()->create(['email' => 'emily@example.test']);

    $known = $this->post('/forgot-password', ['email' => 'emily@example.test']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->getSession()->get('status'))->toBe($known->getSession()->get('status'));
});
