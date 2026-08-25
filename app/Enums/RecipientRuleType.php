<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * How a message template says *who* it goes to (PRD §7.12 · issue #90).
 *
 * > Recipients should be **a rule rather than an address.**
 *
 * The reason is in the issue in one sentence: *"a template holding an email
 * address is a template that emails the wrong person the moment it is
 * reused."* A template says "the Seller"; the rule resolves against
 * `deal_participants` at send time, on the deal actually being sent about.
 *
 * Three types, and each earns its place:
 *
 *  - **Participant role** is the one F5.5 names — "recipient rule by
 *    participant role" — and covers every client-facing message.
 *  - **Primary contact** exists because *"whose deal is this"* is a question
 *    the product already answers (`DealHeader::clientName()`), and a template
 *    addressed to "the client" should not have to guess whether this deal
 *    calls them a Buyer or a Seller. A buy-side and a sell-side deal can then
 *    share one template.
 *  - **Team owner** is the internal recipient, and it is what makes the `push`
 *    channel usable at all: PRD F12.2 is explicit that push carries nothing
 *    client-facing, so an internal channel needs an internal rule. It is also
 *    the address that always exists — every team has one — which is what the
 *    sandbox rail (F5.9, #96) redirects to.
 *
 * Deliberately **not** here: a literal address. That is the whole correction.
 */
enum RecipientRuleType: string implements HasLabel
{
    use ProvidesOptions;

    case ParticipantRole = 'participant_role';
    case PrimaryContact = 'primary_contact';
    /*
     * `team_owners`, plural, and deliberately **not** `team_owner`.
     *
     * That spelling is the `SystemRole` key, and
     * `tests/Isolation/TeamAccessConventionTest.php` scans for it precisely
     * because two vocabularies sharing one literal is how a check written as
     * `= 'team_owner'` starts answering the wrong question. The plural is also
     * the truth: a team may have more than one owner, and this resolves to all
     * of them.
     */
    case TeamOwner = 'team_owners';

    public function label(): string
    {
        return match ($this) {
            self::ParticipantRole => 'A participant in a named role',
            self::PrimaryContact => 'The deal’s main contact',
            self::TeamOwner => 'The team owner',
        };
    }

    /** Whether the rule needs a {@see ParticipantRole} alongside it. */
    public function needsParticipantRole(): bool
    {
        return $this === self::ParticipantRole;
    }

    /**
     * Whether resolving this rule can reach somebody outside the team.
     *
     * A participant or a main contact is a client; a team owner is a
     * colleague. PRD F12.2 keeps push internal, so the push channel may only
     * carry an internal rule — and that check needs this fact rather than a
     * list of cases spelled out at each call site.
     */
    public function isClientFacing(): bool
    {
        return $this !== self::TeamOwner;
    }

    /**
     * The rules a template on this channel may use.
     *
     * @return array<string, string>
     */
    public static function optionsFor(MessageChannel $channel): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($channel === MessageChannel::Push && $case->isClientFacing()) {
                continue;
            }

            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
