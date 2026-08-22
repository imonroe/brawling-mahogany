<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\DealSide;

/**
 * The three facts a deal's generated name is made of (IA §10).
 *
 * A value object rather than a set of relations to walk, for one reason: the
 * facts arrive from different places at different times. The subject property
 * is #61, the client participant is #60, and the side comes from the deal type
 * which exists today. Passing them in means `GenerateDealName` is a pure rule
 * that can be tested against every combination now, including the ones no
 * screen can produce yet.
 *
 * Every field is nullable because every one of them genuinely can be missing.
 * A buyer-side deal is opened before there is a property to buy — that is the
 * normal way round, not an edge case (IA §13.4).
 */
final readonly class DealNameFacts
{
    public function __construct(
        public ?string $streetAddress = null,
        public ?string $clientSurname = null,
        public ?DealSide $side = null,
    ) {}

    /**
     * Nothing to build a name from.
     *
     * Distinct from "the name came out empty": a deal with no facts keeps
     * whatever `generated_name` it already had rather than having it cleared,
     * because losing a name to a half-loaded relation is worse than a stale
     * one.
     */
    public function areEmpty(): bool
    {
        return $this->trimmed($this->streetAddress) === null
            && $this->trimmed($this->clientSurname) === null;
    }

    public function address(): ?string
    {
        return $this->trimmed($this->streetAddress);
    }

    public function surname(): ?string
    {
        return $this->trimmed($this->clientSurname);
    }

    private function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
