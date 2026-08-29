<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\Extraction;
use App\Models\Person;
use App\Models\Team;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Who may read a document, and who may put its dates on the calendar.
 *
 * Two permissions, not one, and the split is the point. Reading is
 * `deals.manage` — starting an extraction spends the team's money and sends a
 * document to a third party, which is not something a read-only role does.
 * **Confirming** is `extraction.confirm`, its own key, already in the catalogue
 * (`Permissions::CONFIRM_EXTRACTION`) since Slice 1 with the description
 * *"Confirm an extracted date or task into the record."*
 *
 * That separation is what makes S66's promise operable rather than rhetorical.
 * Screen Inventory calls it the only thing standing between a model's output
 * and a live contingency calendar; a team that wants that decision to sit with
 * one person can now compose a role that says so, and the ability to *start* an
 * extraction does not carry the ability to accept what comes back.
 *
 * Viewing is `deals.view`, deliberately wider than either: the whole argument
 * for the review screen is that a human looks at it, and a role that can see
 * the deal but not the proposals cannot be asked to check anybody's work.
 */
class ExtractionPolicy
{
    use ChecksTeamPermissions;

    /**
     * Read the team's extraction history, spend and audit (S68 · #118).
     *
     * Takes a `Team` where every other ability here takes a `Deal`, because
     * S68 is a question about the *installation's bill and the team's audit*
     * rather than about any one transaction.
     *
     * It exists rather than reusing `TeamPolicy::update` — which carries the
     * same permission — because the verb would be a lie: this screen writes
     * nothing, and a reader working out who can see a team's spend should not
     * have to know that "update" was the nearest ability to hand. `TeamPolicy::view`
     * was not an option in the other direction: that is any live membership,
     * far too wide for spend and audit.
     */
    public function viewHistory(Person $person, Team $team): bool
    {
        /*
         * `getKey()`, not `belongsToCurrentTeam()`.
         *
         * That helper reads `team_id` off the model — which a **team** does not
         * have, so it returned false for every caller and the screen was a 403
         * for its own owner. `TeamPolicy` makes the same comparison for the
         * same reason (`isCurrent()`); this is the one ability in this file
         * whose subject is the tenant rather than something inside it.
         */
        return $this->currentTeam()?->getKey() === $team->getKey()
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function viewAny(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Extraction $extraction): bool
    {
        return $this->belongsToCurrentTeam($extraction)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /**
     * Accept a proposal into `key_dates`, `tasks`, or the timeline.
     *
     * The one action in this file with legal consequence behind it, and the
     * only one that needs its own key.
     */
    public function confirm(Person $person, Extraction $extraction): bool
    {
        return $this->belongsToCurrentTeam($extraction)
            && $this->allows($person, Permissions::CONFIRM_EXTRACTION);
    }
}
