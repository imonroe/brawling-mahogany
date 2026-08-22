<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Person;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

/**
 * ADR 0003 — recovering a password without the email.
 *
 * The second door here is deliberately *not* a screen. A page that mints reset
 * links for other accounts is an account-takeover button however carefully it
 * is gated, so the alternative channel is shell access on the server — the
 * same bar as reading the database directly, and unlike editing a hash by hand
 * it leaves an audit entry.
 */
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

/** The URL the command printed, so a test can follow it the way a person would. */
function issuedResetUrl(string $email): string
{
    expect(Artisan::call('auth:reset-link', ['email' => $email]))->toBe(0);

    preg_match('#https?://\S+#', Artisan::output(), $matches);

    expect($matches)->not->toBeEmpty('The command printed no link.');

    return $matches[0];
}

it('prints a link that resets the password through the ordinary screen', function (): void {
    $person = Person::factory()->create([
        'email' => 'emily@example.test',
        'password' => Hash::make('the-old-password'),
    ]);

    $url = issuedResetUrl('emily@example.test');

    // Not a parallel flow: the printed link lands on Fortify's own reset
    // screen, carrying a token its own broker minted, and is spent there.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')->where('email', 'emily@example.test'));

    $token = (string) parse_url($url, PHP_URL_PATH);
    $token = basename($token);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'emily@example.test',
        'password' => 'a-long-enough-new-password',
        'password_confirmation' => 'a-long-enough-new-password',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('a-long-enough-new-password', (string) $person->fresh()->password))->toBeTrue();
});

it('changes nothing by itself', function (): void {
    // An operator can *start* a reset. Only the account holder finishes one.
    $person = Person::factory()->create([
        'email' => 'emily@example.test',
        'password' => Hash::make('the-old-password'),
    ]);

    issuedResetUrl('emily@example.test');

    expect(Hash::check('the-old-password', (string) $person->fresh()->password))->toBeTrue();
});

it('audits the issue, with no actor and no team', function (): void {
    $person = Person::factory()->create(['email' => 'emily@example.test']);

    issuedResetUrl('emily@example.test');

    $entry = AuditEntry::query()->where('action', 'auth.password_reset_link_issued')->sole();

    expect($entry->auditable_id)->toBe($person->getKey())
        // An operator with a shell is not a person the application knows, and
        // an account spans every team, so both are null on purpose.
        ->and($entry->actor_person_id)->toBeNull()
        ->and($entry->team_id)->toBeNull();
});

it('refuses an address with no account', function (): void {
    expect(Artisan::call('auth:reset-link', ['email' => 'nobody@example.test']))->not->toBe(0);

    expect(AuditEntry::query()->where('action', 'auth.password_reset_link_issued')->exists())->toBeFalse();
});

it('refuses somebody who has no password to reset', function (): void {
    /*
     * A contact in a team's directory is not an account (#140), and issue #43
     * is explicit that a null password never authenticates by any path.
     * Minting a reset link for one would create credentials out of nothing.
     */
    $contact = Person::factory()->contactOnly()->create();
    $contact->forceFill(['email' => 'contact@example.test'])->save();

    expect(Artisan::call('auth:reset-link', ['email' => 'contact@example.test']))->not->toBe(0);

    expect($contact->fresh()->hasCredentials())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'auth.password_reset_link_issued')->exists())->toBeFalse();
});
