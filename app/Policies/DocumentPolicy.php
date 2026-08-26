<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\Document;
use App\Models\Person;
use App\Models\Stage;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Who may read and change an uploaded file (PRD §4.6, §9 · issues #63, #98).
 *
 * Built on the permissions for **what the document is attached to** rather
 * than on a `documents.*` key of its own, for the reason `TaskPolicy` is built
 * on `deals.*`: inventing a permission no shipped role holds puts a key in the
 * catalogue that nothing grants. F6.5's restricted categories are the
 * exception already waiting — `VIEW_RESTRICTED_DOCUMENT` is in the catalogue.
 *
 * ## Why it follows the subject
 *
 * It was `properties.*` for every document, which was true while Slice 2's
 * only documents were a property's photographs. S21 attached them to deals,
 * and the two halves stopped agreeing: `DealDocumentController::index()`
 * authorizes `view` on the **deal**, so a role holding `deals.view` without
 * `properties.view` got a tab listing documents that then refused to download.
 *
 * No shipped role can reach that — Team Member holds both — but S75 lets a
 * team compose its own roles, and a permission pair nobody can currently
 * separate is not the same as one nobody ever will. Following the subject
 * makes the two answers the same question.
 */
class DocumentPolicy
{
    use ChecksTeamPermissions;

    /**
     * S50's index, which is a list rather than a document.
     *
     * The **wider** of the two subject permissions, because the screen shows
     * both kinds and each row is still authorized on its way out. Requiring
     * both would hide a team's own deal documents from somebody who can open
     * every one of them from the deal itself, which is hiding a fact rather
     * than protecting one — the reasoning S47's queue records for gating on
     * `deals.view` instead of `message.approve`.
     */
    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS)
            || $this->allows($person, Permissions::VIEW_PROPERTIES);
    }

    /**
     * The two halves `viewAny` is the union of, asked separately.
     *
     * `DocumentController::readable()` needs to know *which* subjects to
     * include, not merely whether there are any — so the question lives here
     * with the rest of the document authorization rather than as a second
     * reading of the permission catalogue in a controller.
     */
    public function viewDeals(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function viewProperties(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_PROPERTIES);
    }

    public function view(Person $person, Document $document): bool
    {
        return $this->belongsToCurrentTeam($document)
            && $this->allows($person, $this->viewPermission($document));
    }

    public function update(Person $person, Document $document): bool
    {
        return $this->belongsToCurrentTeam($document)
            && $this->allows($person, $this->managePermission($document));
    }

    public function delete(Person $person, Document $document): bool
    {
        return $this->update($person, $document);
    }

    /**
     * The permission that answers "may this person see the thing it hangs off".
     *
     * The default is deliberately the **property** pair rather than `false`:
     * every document that exists today is on a property or a deal, and a
     * refusal for an unmapped morph would lock somebody out of their own file
     * on the day a fourth subject type is added. `Stage` is mapped ahead of
     * its use because a stage belongs to a workflow belongs to a deal, so the
     * answer is not a guess.
     *
     * The keys are class names because this application enforces no morph map
     * — `getMorphClass()` returns the FQCN, and that is what the column holds.
     * A string literal here would match nothing and fail silently open to the
     * default, which is exactly the shape of bug this method exists to fix.
     *
     * Adding a documentable type means adding it here.
     */
    private function viewPermission(Document $document): string
    {
        return match ($document->documentable_type) {
            Deal::class, Stage::class => Permissions::VIEW_DEALS,
            default => Permissions::VIEW_PROPERTIES,
        };
    }

    private function managePermission(Document $document): string
    {
        return match ($document->documentable_type) {
            Deal::class, Stage::class => Permissions::MANAGE_DEALS,
            default => Permissions::MANAGE_PROPERTIES,
        };
    }
}
