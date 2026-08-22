<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The forgot-password screen answers the same way for every address.
 *
 * Issue #43: *"Do not disclose whether an email address exists on the
 * forgot-password screen. The confirmation is identical either way."*
 *
 * Fortify's default reports `passwords.user` — "We can't find a user with that
 * email address" — which turns the form into a working account-enumeration
 * oracle. Every other failure still reports itself: a throttled request and a
 * broken mailer are the person's problem to see, not a fact about somebody
 * else's account.
 */
class PasswordResetLinkRequested implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request): Response
    {
        if ($this->status !== Password::INVALID_USER) {
            return (new \Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse($this->status))
                ->toResponse($request);
        }

        // Byte-identical to the success case, including the status key the
        // screen reads.
        return back()->with('status', trans(Password::RESET_LINK_SENT));
    }
}
