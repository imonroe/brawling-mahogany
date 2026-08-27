<?php

declare(strict_types=1);

use App\Enums\SuppressionReason;
use App\Models\AuditEntry;
use App\Models\SuppressedAddress;

/**
 * The door on the suppression list (#95 · round 1 of review).
 *
 * `Suppression::lift()` shipped with no caller, and `SuppressionReason::Manual`
 * was produced by nothing, while `resources/help/automation.md` told a team to
 * *"ask support"* — whose only route was a `psql` session. That is CLAUDE.md's
 * finding twice: *"a rail with no UI is a rule nobody can pull"*, and *"a
 * reader with no writer is as dead as a row nothing can reach."*
 */
it('says nothing is there when nothing is', function (): void {
    $this->artisan('mail:suppression', ['email' => 'dana@example.test'])
        ->expectsOutputToContain('is not suppressed')
        ->assertSuccessful();
});

it('lifts a suppression and records who decided to', function (): void {
    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);

    $this->artisan('mail:suppression', ['email' => 'DANA@Example.test', '--lift' => true])
        ->expectsOutputToContain('no longer suppressed')
        ->assertSuccessful();

    expect(SuppressedAddress::suppresses('dana@example.test'))->toBeNull();

    /*
     * Audited because a lifted suppression that starts bouncing again is
     * exactly the sequence that damages the whole account's sending
     * reputation, and *"who decided this address was fine"* is the first
     * question asked.
     */
    $entry = AuditEntry::query()->where('action', 'mail.suppression_lifted')->sole();

    expect($entry->team_id)->toBeNull()
        ->and($entry->before['reason_code'])->toBe('hard_bounce');
});

it('makes the manual reason reachable, which nothing else did', function (): void {
    $this->artisan('mail:suppression', [
        'email' => 'dana@example.test',
        '--suppress' => true,
        '--reason' => 'asked us to stop',
    ])->assertSuccessful();

    expect(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::Manual)
        ->and(AuditEntry::query()->where('action', 'mail.suppression_added')->count())->toBe(1);
});

it('refuses an address it could never have sent to', function (): void {
    /*
     * A typo would otherwise leave a row nothing can ever match — permanent,
     * invisible, and binding every team on the platform.
     */
    $this->artisan('mail:suppression', ['email' => 'not-an-address', '--suppress' => true])
        ->expectsOutputToContain('is not an email address')
        ->assertFailed();

    expect(SuppressedAddress::query()->count())->toBe(0);
});

it('refuses to lift and suppress in one breath', function (): void {
    $this->artisan('mail:suppression', [
        'email' => 'dana@example.test',
        '--lift' => true,
        '--suppress' => true,
    ])->assertFailed();
});
