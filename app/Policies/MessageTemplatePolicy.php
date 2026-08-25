<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Message templates (S45, S46 · issue #90).
 *
 * `templates.manage`, the same permission the workflow templates carry: both
 * are a team deciding how its process runs, and a team that may compose a
 * workflow may compose the words that go with it.
 *
 * Unlike `DealTypePolicy` and `WorkflowTemplatePolicy`, there is no system row
 * to reason about — every message template belongs to exactly one team, which
 * is the decision the migration argues in full. So `belongsToCurrentTeam()`
 * does the visibility half, backed by the global scope behind it.
 */
class MessageTemplatePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function view(Person $person, MessageTemplate $template): bool
    {
        return $this->belongsToCurrentTeam($template)
            && $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    /**
     * An archived template is not editable.
     *
     * Same reasoning as `DealTypePolicy::update()`: archiving frees the name,
     * so renaming an archived row is a name freed and re-taken behind the
     * validator's back — and a template nobody can reach from a picker is not
     * a thing to keep polishing.
     */
    public function update(Person $person, MessageTemplate $template): bool
    {
        return ! $template->isArchived() && $this->view($person, $template);
    }

    /** Archive, never delete. There is no destroy route at all. */
    public function archive(Person $person, MessageTemplate $template): bool
    {
        return $this->update($person, $template);
    }

    /**
     * Its own ability, not a second use of `archive` — which is false for a
     * row that is already archived, and that is exactly the row being
     * restored. S76 found that the hard way.
     */
    public function restore(Person $person, MessageTemplate $template): bool
    {
        return $template->isArchived() && $this->view($person, $template);
    }

    /**
     * Sending a test copy of this template to yourself (S46, S48).
     *
     * Deliberately the same permission as editing rather than a looser one.
     * It is the only path in this slice that puts a real message on a real
     * mail transport, and while it can only reach the person who asked for it,
     * a narrower gate here would be a second answer to "who may work on
     * templates" for no gain.
     */
    public function test(Person $person, MessageTemplate $template): bool
    {
        return $this->view($person, $template);
    }
}
