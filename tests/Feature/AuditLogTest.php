<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only security record (PRD §6.2, §9 Audit · issue #51).
 *
 * *"Append-only means enforced, not intended."*
 */
it('refuses to update an entry, at the model and at the table', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $entry = app(AuditLogger::class)->record(action: 'test.performed');

    expect(fn () => $entry->forceFill(['action' => 'test.rewritten'])->save())
        ->toThrow(RuntimeException::class);

    // The model is a convention; the trigger is the rule. A raw query, a
    // tinker session, and a future developer who has not read the ADR all
    // meet the same refusal.
    expect(fn () => DB::transaction(
        fn () => DB::table('audit_log')->where('id', $entry->getKey())->update(['action' => 'test.rewritten']),
    ))->toThrow(QueryException::class);

    expect(AuditEntry::query()->find($entry->getKey())->action)->toBe('test.performed');

    unset($member);
});

it('refuses to delete an entry, at the model and at the table', function (): void {
    [$team] = $this->teamWithMember();

    $this->withTeam($team);

    $entry = app(AuditLogger::class)->record(action: 'test.performed');

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);

    expect(fn () => DB::transaction(
        fn () => DB::table('audit_log')->where('id', $entry->getKey())->delete(),
    ))->toThrow(QueryException::class);

    expect(AuditEntry::query()->find($entry->getKey()))->not->toBeNull();
});

it('refuses to be truncated', function (): void {
    // The hole a row-level trigger leaves. Postgres does not fire `FOR EACH
    // ROW` triggers on `TRUNCATE`, so `DELETE FROM audit_log` raises while
    // `TRUNCATE audit_log` empties the table without a word — which is
    // exactly the shape of "somebody tidying up" the table exists to survive.
    [$team] = $this->teamWithMember();

    $this->withTeam($team);

    app(AuditLogger::class)->record(action: 'test.performed');

    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE audit_log')))
        ->toThrow(QueryException::class);

    expect(AuditEntry::query()->count())->toBeGreaterThan(0);
});

it('has no updated_at to rewrite', function (): void {
    expect(Schema::hasColumn('audit_log', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('audit_log', 'deleted_at'))->toBeFalse();
});

it('keeps a client’s phone number out of before and after', function (): void {
    // PRD §9: "No PII in logs, ever." The before/after payloads are where an
    // audit log leaks — they can capture a client's number verbatim.
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $person = Person::factory()->contactOnly()->create([
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'phone' => '+1 303 555 0100',
    ]);

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => $person->getKey(),
    ]);

    $membership->forceFill(['notes' => 'Lives at 123 Main St.'])->save();

    $entry = app(AuditLogger::class)->recordChange('person.updated', $membership);

    $serialised = json_encode([$entry->before, $entry->after], JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain('303 555 0100')
        ->and($serialised)->not->toContain('123 Main St')
        ->and($serialised)->not->toContain('claire@example.test')
        // The fact that the field changed is still recorded, which is the
        // whole obligation.
        ->and($entry->after)->toHaveKey('notes')
        ->and($entry->after['notes'])->toBe(AuditRedactor::MARKER);
});

it('redacts a nested payload too', function (): void {
    $redacted = (new AuditRedactor)->redact([
        'name' => 'Claire',
        'contact' => ['email' => 'claire@example.test', 'phone' => '3035550100'],
        'sending_identity_email' => 'team@example.test',
    ]);

    expect($redacted['name'])->toBe('Claire')
        ->and($redacted['contact']['email'])->toBe(AuditRedactor::MARKER)
        ->and($redacted['contact']['phone'])->toBe(AuditRedactor::MARKER)
        // Matched on the suffix, so a prefixed column is caught too.
        ->and($redacted['sending_identity_email'])->toBe(AuditRedactor::MARKER);
});

it('records authentication', function (): void {
    // PRD §9 lists authentication first among what the audit log must cover.
    $person = Person::factory()->create([
        'email' => 'emily@example.test',
        'password' => Hash::make('a-long-enough-password'),
    ]);

    $this->post('/login', ['email' => $person->email, 'password' => 'a-long-enough-password']);

    $entry = AuditEntry::query()->where('action', 'auth.signed_in')->sole();

    expect($entry->actor_person_id)->toBe($person->getKey());

    $this->post('/logout');

    expect(AuditEntry::query()->where('action', 'auth.signed_out')->exists())->toBeTrue();
});

it('records a failed sign-in without writing the address', function (): void {
    Person::factory()->create(['email' => 'emily@example.test']);

    $this->post('/login', ['email' => 'emily@example.test', 'password' => 'wrong-password']);

    $entry = AuditEntry::query()->where('action', 'auth.failed')->sole();

    expect(json_encode($entry->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('emily@example.test');
});
