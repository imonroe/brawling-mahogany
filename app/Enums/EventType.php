<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What kind of thing is in the diary (PRD §4.8 F8.1 · issue #105).
 *
 * F8.1's list verbatim: *"showings, open houses, inspections, appraisals,
 * closings, contractor visits"*, plus `other`, because a team's week contains
 * things a six-row enum did not anticipate and an event nobody can file is an
 * event that gets kept somewhere else.
 *
 * ## Why an event is not a key date, and never becomes one
 *
 * Screen Inventory calls S57 hard for one reason: *"events and deadlines are
 * different things sharing a grid."* An **event** is a block of time somebody
 * attends — it has a start, an end, and a place. A **key date** is a moment
 * with legal consequences that nobody attends. A closing appointment at 9am on
 * the 20th and the closing *date* of the 20th are two different rows that
 * happen to sit on the same square, and collapsing them would lose the half
 * that matters: the appointment can be moved by a phone call, and the deadline
 * cannot.
 *
 * IA §2 gives them different client-facing words for the same reason.
 */
enum EventType: string implements HasLabel
{
    use ProvidesOptions;

    case Showing = 'showing';
    case OpenHouse = 'open_house';
    case Inspection = 'inspection';
    case Appraisal = 'appraisal';
    case Closing = 'closing';
    case ContractorVisit = 'contractor_visit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Showing => 'Showing',
            self::OpenHouse => 'Open House',
            self::Inspection => 'Inspection',
            self::Appraisal => 'Appraisal',
            self::Closing => 'Closing',
            self::ContractorVisit => 'Contractor Visit',
            self::Other => 'Other',
        };
    }

    /**
     * The client-facing word, when a client ever sees one (IA §2).
     *
     * IA §2 records `events` as *"(varies: Inspection, Open House)"* — the
     * type **is** the client word for most of them, because these are ordinary
     * English nouns rather than product vocabulary. `contractor_visit` is the
     * exception worth spelling out, and `other` has no honest client word at
     * all, so it returns null and the caller declines to name it rather than
     * showing a client the word "Other".
     */
    public function clientLabel(): ?string
    {
        return match ($this) {
            self::ContractorVisit => 'Contractor visit',
            self::Other => null,
            default => $this->label(),
        };
    }
}
