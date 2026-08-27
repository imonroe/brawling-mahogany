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

    /*
     * **And the row it points at is still there** — round 2 of review.
     *
     * `lift()` used to hard-delete, so the entry's `auditable_id` resolved to
     * nothing: no address, no team, no actor, nothing an append-only record
     * could answer with. The command audits because *"who decided this address
     * was fine"* is the first question asked when a lifted address starts
     * bouncing again, and the entry could not answer it about **which**
     * address. (Putting the address in the entry is not the fix: the audit
     * redactor removes it, correctly.)
     *
     * The old assertions above are both true of that implementation, which is
     * why this one is the test.
     */
    $subject = SuppressedAddress::withTrashed()->find($entry->auditable_id);

    expect($subject)->not->toBeNull()
        ->and($subject->email)->toBe('dana@example.test')
        ->and($subject->trashed())->toBeTrue();
});

it('suppresses again after a lift, rather than colliding in silence', function (): void {
    /*
     * The unique index covers soft-deleted rows, so an insert after a lift
     * would be ignored — leaving an address that has just hard-bounced
     * unsuppressed, through the door opened to make lifting auditable.
     */
    $suppression = app(App\Support\Delivery\Suppression::class);

    $suppression->record('dana@example.test', SuppressionReason::HardBounce);
    $suppression->lift('dana@example.test');

    expect(SuppressedAddress::suppresses('dana@example.test'))->toBeNull()
        ->and($suppression->record('dana@example.test', SuppressionReason::Complaint))->toBeTrue()
        ->and(SuppressedAddress::suppresses('dana@example.test'))->toBe(SuppressionReason::Complaint)
        // Restored rather than inserted afresh: a lift and a re-suppression
        // are one row's story, and an audit entry already points at that id.
        ->and(SuppressedAddress::withTrashed()->where('email', 'dana@example.test')->count())->toBe(1);
});

it('shows that a lifted address was once suppressed', function (): void {
    SuppressedAddress::factory()->create(['email' => 'dana@example.test']);

    app(App\Support\Delivery\Suppression::class)->lift('dana@example.test');

    $this->artisan('mail:suppression', ['email' => 'dana@example.test'])
        ->expectsOutputToContain('Was suppressed for')
        ->assertSuccessful();
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

it('refuses an address the send path could never have used', function (): void {
    /*
     * `emily(work)@bosart.test`, not `not-an-address` — round 2 of review.
     * The first fixture is rejected by every validator on earth, so it proved
     * nothing about *which* validator was chosen; this one is a legal RFC 5322
     * address with a comment in it, passes Laravel's `email` and `email:rfc`,
     * and throws inside `Symfony\Component\Mime\Address`.
     *
     * `CLAUDE.md` records the rule: validate with the parser that will consume
     * it. The consequence here is a permanent, account-wide row matching an
     * address this product can never send to — which is exactly what the
     * guard's own comment says it exists to prevent.
     */
    $this->artisan('mail:suppression', ['email' => 'emily(work)@bosart.test', '--suppress' => true])
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
