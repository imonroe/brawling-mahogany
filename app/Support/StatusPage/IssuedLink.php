<?php

declare(strict_types=1);

namespace App\Support\StatusPage;

use App\Models\StatusPageLink;

/**
 * A link and the one moment its plaintext exists (issue #110).
 *
 * The row never holds it — see `StatusPageLink` — so the issuer hands both
 * back together and the caller either puts the plaintext in an email or prints
 * it at a console (ADR 0003's second door) and then loses it.
 */
final readonly class IssuedLink
{
    public function __construct(
        public StatusPageLink $link,
        public string $token,
    ) {}

    public function url(): string
    {
        return url('/s/'.$this->token);
    }
}
