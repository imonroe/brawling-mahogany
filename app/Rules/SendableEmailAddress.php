<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * An address the mail transport will actually accept (PRD §8.5 · issue #12).
 *
 * ## Laravel's `email` rule and Symfony's parser disagree
 *
 * `emily(work)@bosart.test` passes `email` **and** `email:rfc` — the
 * parenthesised comment is legal RFC 5322 — and then throws when
 * `Symfony\Component\Mime\Address` is constructed from it. Confirmed
 * end-to-end rather than assumed; `tests/Unit/SendableEmailAddressTest.php`
 * pins both halves so a framework upgrade that closes the gap does not close
 * it silently.
 *
 * ## Why the gap is expensive here rather than annoying
 *
 * The throw does not happen at save time. It happens in a queue worker inside
 * `Mail::send()`, **after** `action_instances.message_key` has been claimed —
 * and a claimed key is deliberately never retried, because a provider can
 * accept a message and then time out (CLAUDE.md: *"an idempotency key you
 * generate is not the one the provider hands back"*). So one address typed
 * into a settings form fails every automated message the team sends, for good,
 * and the queue tells them *"the mail transport rejected this message"* —
 * which points at SES for a value that came from a form here.
 *
 * ## Validate with the thing that will consume it
 *
 * So the rule is not a better pattern. It is the **same parser**, run early:
 * the only check guaranteed to agree with the one that matters is the one that
 * matters. That is the shape CLAUDE.md calls *"both ends of a paired
 * invariant"* — and {@see \App\Support\Mail\SendingIdentity::address()} is the
 * other end, skipping an unparseable candidate rather than throwing, for rows
 * stored before this rule existed.
 *
 * Deliberately used **alongside** `email`, not instead of it. Symfony accepts
 * `a@b`, which is a valid address and almost never what somebody meant to
 * type; the two rules refuse different mistakes.
 */
final class SendableEmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be an email address.')->translate();

            return;
        }

        try {
            new Address($value);
        } catch (Throwable) {
            $fail('The :attribute must be an address mail can be sent to. Remove any comments, angle brackets, or spaces.')->translate();
        }
    }
}
