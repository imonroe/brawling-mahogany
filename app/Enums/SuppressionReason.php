<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Why an address may not be written to again (PRD §4.5 F5.8, §12.2 · #95).
 *
 * Suppression is the one deliberately **account-wide** record in this product:
 * a hard bounce is a fact about the address, not about the team that happened
 * to send to it, and SES measures the bounce and complaint rates across the
 * whole account. `SuppressedAddress` argues that at length; this enum is the
 * list of things that can put a row there.
 */
enum SuppressionReason: string implements HasLabel
{
    use ProvidesOptions;

    /** The receiving server said this address does not exist. Permanent. */
    case HardBounce = 'hard_bounce';

    /** Somebody marked a message as spam. Permanent, and more serious. */
    case Complaint = 'complaint';

    /** A platform operator put it there by hand. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::HardBounce => 'Address does not exist',
            self::Complaint => 'Marked as spam',
            self::Manual => 'Suppressed by an operator',
        };
    }

    /**
     * What a person is told, in words rather than in a protocol.
     *
     * #95: *"An agent needs to know that the disclosure email never arrived,
     * and needs it to say so in plain language — not `SMTP 550`."* The
     * protocol's own words are kept beside this on the delivery row for
     * anybody who wants them; this is the sentence the screen leads with.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::HardBounce => 'Their mail server said this address does not exist, so nothing more will be sent to it. Check the address with them and correct it on the deal.',
            self::Complaint => 'Somebody at this address marked a message from you as spam. Nothing more will be sent to it, and that is deliberate: continuing to write to somebody who has reported you puts every other message your team sends at risk.',
            self::Manual => 'A platform operator suppressed this address.',
        };
    }

    /**
     * Whether crossing this threshold puts more than one team at risk.
     *
     * PRD §12.2: complaints must stay under **0.1%** — the level at which
     * Amazon reviews the account rather than the team. A bounce is a bad
     * address; a complaint is a reputation event that every other team on the
     * platform pays for, which is why it is escalated differently.
     */
    public function threatensTheAccount(): bool
    {
        return $this === self::Complaint;
    }
}
