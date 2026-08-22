<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\WorkflowTemplate;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Workflow templates (S41 · issue #64).
 *
 * Same shape as `DealTypePolicy`: a null `team_id` is a system template from a
 * pack, visible to everybody and editable by nobody. A team that wants to
 * change a packaged workflow copies it first — which is what installing a pack
 * does, and why installation copies rather than references.
 */
class WorkflowTemplatePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function view(Person $person, WorkflowTemplate $template): bool
    {
        return $this->visibleHere($template)
            && $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function update(Person $person, WorkflowTemplate $template): bool
    {
        return ! $template->isSystem()
            && $this->visibleHere($template)
            && $this->allows($person, Permissions::MANAGE_TEMPLATES);
    }

    public function delete(Person $person, WorkflowTemplate $template): bool
    {
        return $this->update($person, $template);
    }

    /**
     * Running a template on a deal is deal work, not template work.
     *
     * Heather starts a listing workflow every week and does not edit the
     * template that defines it.
     */
    public function instantiate(Person $person, WorkflowTemplate $template): bool
    {
        return $this->visibleHere($template)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    private function visibleHere(WorkflowTemplate $template): bool
    {
        return $template->isSystem()
            || $template->team_id === $this->currentTeam()?->getKey();
    }
}
