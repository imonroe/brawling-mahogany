<?php

declare(strict_types=1);

namespace App\Support\Messages;

/**
 * One message, rendered against one deal (S48 · F5.6).
 *
 * Carries what would be sent **and** what is wrong with it, because those are
 * one answer rather than two. Issue #93: *"A missing merge field blocks
 * approval"*, and a renderer that returned only the words would leave every
 * caller to work out for itself whether they were safe to send.
 */
final readonly class RenderedMessage
{
    /**
     * @param  list<string>  $unresolved  Registered fields with nothing behind them on this deal.
     * @param  list<string>  $unknown  Tokens no field answers to — a template written before a rename, or a typo.
     * @param  list<string>  $malformed  Unbalanced brace runs: `{{ client_name }` with a brace dropped.
     */
    public function __construct(
        public ?string $subject,
        public ?string $bodyHtml,
        public string $bodyText,
        public array $unresolved = [],
        public array $unknown = [],
        public array $malformed = [],
    ) {}

    /**
     * Whether this is safe to put in front of a client.
     *
     * Three lists, failing three different ways. An **unknown** token is a
     * template that was never valid. An **unresolved** one is a template that
     * is valid and a deal that has not got there yet — "See the listing at ."
     * on a deal with no MLS link. A **malformed** one is a brace run that was
     * never a token at all, which is the case that used to pass: the
     * substitution leaves the braces in the body, so the client reads the
     * template's internals. None of the three may be sent.
     */
    public function isComplete(): bool
    {
        return $this->unresolved === [] && $this->unknown === [] && $this->malformed === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'bodyHtml' => $this->bodyHtml,
            'bodyText' => $this->bodyText,
            'unresolved' => $this->unresolved,
            'unknown' => $this->unknown,
            'malformed' => $this->malformed,
            'isComplete' => $this->isComplete(),
        ];
    }
}
