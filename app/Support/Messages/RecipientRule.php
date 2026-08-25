<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;

/**
 * *Who a message goes to*, as a rule rather than an address (PRD §7.12).
 *
 * A value object rather than a bare array because the array has a shape and
 * four places read it — the validator, the editor's payload, the resolver, and
 * the preview. An array of unknown shape read in four places is four slightly
 * different readings within a month.
 *
 * @see RecipientRuleType for why the three types are the three types.
 */
final readonly class RecipientRule
{
    private function __construct(
        public RecipientRuleType $type,
        public ?ParticipantRole $participantRole = null,
    ) {}

    public static function participantRole(ParticipantRole $role): self
    {
        return new self(RecipientRuleType::ParticipantRole, $role);
    }

    public static function of(RecipientRuleType $type): self
    {
        return new self($type);
    }

    /**
     * Read a stored rule, refusing anything that is not one.
     *
     * A stored rule reaches here from JSONB, which the database does not type
     * for us. An unknown `type`, or a `participant_role` rule with no role,
     * cannot resolve to anybody — and a send that resolves to nobody is a
     * milestone email that silently never arrives, which is the failure this
     * whole feature exists to prevent. So it throws rather than returning a
     * rule that will quietly find no recipients.
     *
     * @param  array<string, mixed>  $rule
     *
     * @throws MalformedRecipientRule
     */
    public static function fromArray(array $rule): self
    {
        $type = RecipientRuleType::tryFrom((string) ($rule['type'] ?? ''));

        if ($type === null) {
            throw MalformedRecipientRule::unknownType((string) ($rule['type'] ?? ''));
        }

        if (! $type->needsParticipantRole()) {
            return new self($type);
        }

        $role = ParticipantRole::tryFrom((string) ($rule['participantRole'] ?? ''));

        if ($role === null) {
            throw MalformedRecipientRule::missingParticipantRole();
        }

        return new self($type, $role);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $rule = ['type' => $this->type->value];

        if ($this->participantRole !== null) {
            $rule['participantRole'] = $this->participantRole->value;
        }

        return $rule;
    }

    /**
     * A sentence for the editor and the automation list.
     *
     * IA §10 keeps sentence case for everything a person reads, and the label
     * lives here rather than in the page so S45, S46 and S44 cannot describe
     * the same rule three ways.
     */
    public function describe(): string
    {
        return match ($this->type) {
            RecipientRuleType::ParticipantRole => 'the '.mb_strtolower(
                $this->participantRole?->label() ?? 'participant',
            ),
            RecipientRuleType::PrimaryContact => 'the deal’s main contact',
            RecipientRuleType::TeamOwner => 'the team owner',
        };
    }
}
