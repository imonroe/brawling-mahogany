<?php

declare(strict_types=1);

use App\Rules\SendableEmailAddress;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mime\Address;

/**
 * The gap between Laravel's `email` rule and the parser that actually sends.
 *
 * Round 2 of review on #12 found it end-to-end: an address a team types into
 * Settings → Team saves cleanly and then throws in a queue worker, after
 * `action_instances.message_key` has been claimed — which is deliberately
 * never retried. One typo, every automated message dead, and a queue entry
 * blaming the mail transport for a value that came from a form.
 */
it('pins the disagreement it exists to close', function (): void {
    $address = 'emily(work)@bosart.test';

    /*
     * Both halves asserted, not just ours. If a framework upgrade teaches
     * `email:rfc` to refuse this, the rule becomes redundant — and this test
     * is what says so out loud instead of leaving a class nobody can explain.
     * The parenthesised comment is legal RFC 5322, which is why the rule that
     * claims to implement the RFC accepts it.
     */
    expect(Validator::make(['e' => $address], ['e' => ['email']])->passes())->toBeTrue()
        ->and(Validator::make(['e' => $address], ['e' => ['email:rfc']])->passes())->toBeTrue()
        ->and(fn (): Address => new Address($address))->toThrow(InvalidArgumentException::class);

    expect(Validator::make(['e' => $address], ['e' => [new SendableEmailAddress]])->passes())
        ->toBeFalse();
});

it('accepts what the transport accepts', function (string $address): void {
    expect(Validator::make(['e' => $address], ['e' => [new SendableEmailAddress]])->passes())
        ->toBeTrue();
})->with([
    'emily@bosart.test',
    'emily+listings@bosart.test',
    '"e m"@bosart.test',
]);

it('lets the nullable case through, because the column is nullable', function (mixed $value): void {
    expect(Validator::make(['e' => $value], ['e' => ['nullable', new SendableEmailAddress]])->passes())
        ->toBeTrue();
})->with([null, '']);

it('refuses what the transport would throw on', function (string $address): void {
    expect(Validator::make(['e' => $address], ['e' => [new SendableEmailAddress]])->passes())
        ->toBeFalse();
})->with([
    'a comment' => 'emily(work)@bosart.test',
    'a tab' => "emily\tb@bosart.test",
    'a display name' => 'Emily <emily@bosart.test>',
]);
