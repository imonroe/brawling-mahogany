<?php

declare(strict_types=1);

use App\Logging\RedactPii;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * PRD §9: "No PII in logs, ever."
 *
 * These assertions are the rule. If one of them can be made to fail by a new
 * field, the processor grows — the rule does not bend.
 */
function record(string $message, array $context = []): LogRecord
{
    return (new RedactPii)(new LogRecord(
        datetime: new DateTimeImmutable('2026-08-20T15:00:00+00:00'),
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: $context,
    ));
}

it('redacts context keys that name personal data', function (): void {
    $result = record('Person updated', [
        'email' => 'emily@example.com',
        'phone' => '303-555-0142',
        'first_name' => 'Emily',
        'street_address' => '123 Main St',
        'transaction_value' => 48500000,
        'deal_id' => '01J8XZ',
    ]);

    expect($result->context['email'])->toBe(RedactPii::REDACTED)
        ->and($result->context['phone'])->toBe(RedactPii::REDACTED)
        ->and($result->context['first_name'])->toBe(RedactPii::REDACTED)
        ->and($result->context['street_address'])->toBe(RedactPii::REDACTED)
        ->and($result->context['transaction_value'])->toBe(RedactPii::REDACTED)
        // Identifiers are how a log stays useful. They are not PII.
        ->and($result->context['deal_id'])->toBe('01J8XZ');
});

it('masks personal data interpolated into a message', function (): void {
    // This is how PII actually reaches a log: not in a well-named key, but in
    // a string somebody built with a model attribute in it.
    expect(record('Sent milestone email to emily@example.com')->message)
        ->toBe('Sent milestone email to '.RedactPii::REDACTED);

    expect(record('Called (303) 555-0142 about the inspection')->message)
        ->toContain(RedactPii::REDACTED)
        ->not->toContain('555-0142');

    expect(record('Routing 021000021 on the earnest money cheque')->message)
        ->toContain(RedactPii::REDACTED)
        ->not->toContain('021000021');
});

it('redacts nested context', function (): void {
    $result = record('Extraction reviewed', [
        'extraction' => [
            'model' => 'a-vision-model',
            'source_snippet' => 'Buyer: Emily Bosart, emily@example.com',
            'client' => ['email' => 'emily@example.com'],
        ],
    ]);

    expect($result->context['extraction']['model'])->toBe('a-vision-model')
        ->and($result->context['extraction']['source_snippet'])->not->toContain('@example.com')
        ->and($result->context['extraction']['client']['email'])->toBe(RedactPii::REDACTED);
});

it('catches a sensitive word wherever it appears in the key', function (): void {
    // The first version matched keys by exact equality, so `client_email`
    // and `owner_name` sailed through. Model attributes rarely arrive under
    // their bare names.
    $result = record('Deal updated', [
        'client_email' => 'emily@example.com',
        'clientPhone' => '303-555-0142',
        'owner_name' => 'Heather Nguyen',
        'billing_address' => '123 Main St',
        'purchase_amount' => 48500000,
    ]);

    foreach (['client_email', 'clientPhone', 'owner_name', 'billing_address', 'purchase_amount'] as $key) {
        expect($result->context[$key])->toBe(RedactPii::REDACTED, $key.' should be redacted');
    }
});

it('keeps the keys that make a log useful', function (): void {
    // Over-redaction has a cost too: a log with nothing identifying in it
    // cannot be followed. These are the ones worth protecting from the
    // scrubber — note `key_dates`, which is a table in this product.
    $useful = [
        'deal_id' => '01J8XZ',
        'stage_id' => '01J8XY',
        'key_dates' => 3,
        'job_name' => 'SendMilestoneEmail',
        'queue_name' => 'automation',
        'gate_type' => 'document_present',
        // The product's own process vocabulary. None of it is a person.
        'stage_name' => 'Under Contract',
        'workflow_name' => 'Seller — Standard',
        'task_name' => 'Order the inspection',
        'gate_name' => 'Inspection report received',
        'action_name' => 'Send milestone email',
        'milestone_label' => 'Your home is on the market',
        'reason_code' => 'gate_unmet',
        'attempts' => 3,
        'namespace' => 'App\\Jobs',
    ];

    expect(record('Advanced a stage', $useful)->context)->toBe($useful);
});

it('still redacts the names that are somebody’s name', function (): void {
    // `deal_name` is a street address (IA §10), and the rest are people.
    $result = record('Deal renamed', [
        'deal_name' => '123 Main St',
        'client_name' => 'Emily Bosart',
        'person_name' => 'Heather Nguyen',
        'property_name' => '123 Main St',
        // Free text a person typed, which routinely quotes a client.
        'reason' => 'Seller said the buyer’s lender was slow',
    ]);

    foreach (array_keys($result->context) as $key) {
        expect($result->context[$key])->toBe(RedactPii::REDACTED, $key.' should be redacted');
    }
});

it('redacts an author, which is a person', function (): void {
    // `author` contains `auth`, so the never-logged tier catches it first —
    // and that is the right answer anyway. Pinned because the allowlist used
    // to carry a dead entry claiming the opposite.
    expect(record('Note added', ['author' => 'Heather Nguyen'])->context['author'])
        ->toBe(RedactPii::REDACTED);
});

it('never lets a suffix launder a credential', function (): void {
    /*
     * The allowlist is evaluated before the block list, which makes it the
     * more powerful of the two — so every one of these ends in a suffix the
     * allowlist would otherwise wave through. This is the case that made the
     * allowlist safe to have at all.
     */
    $laundered = [
        'session_id' => 'aBcD1234',
        'password_reset_code' => 'xyz789',
        'token_id' => 'tok_123',
        'credential_id' => 'cred_123',
        'api_key_version' => 'v2-abcdef',
        'zip_code' => '80202',
        'postal_code' => '80202',
        'account_id' => '000123456',
        'card_type' => 'visa-4242',
        'auth_state' => 'nonce-abc',
    ];

    $result = record('Probe', $laundered);

    foreach (array_keys($laundered) as $key) {
        expect($result->context[$key])->toBe(RedactPii::REDACTED, $key.' must never be logged');
    }
});

it('redacts credentials and tokens', function (): void {
    $result = record('Provider configured', [
        'api_key' => 'sk-live-abcdef',
        'authorization' => 'Bearer abcdef',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    expect($result->context['api_key'])->toBe(RedactPii::REDACTED)
        ->and($result->context['authorization'])->toBe(RedactPii::REDACTED)
        ->and($result->context['two_factor_secret'])->toBe(RedactPii::REDACTED);
});

it('never lets a document body reach a log', function (): void {
    // PRD §8.4: a refused document is discarded and the refusal logged
    // without the file.
    $result = record('Upload refused', [
        'category' => 'bank_statement',
        'body' => 'Account 000123456789 — statement for August',
    ]);

    expect($result->context['category'])->toBe('bank_statement')
        ->and($result->context['body'])->toBe(RedactPii::REDACTED);
});
