<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\TeamMembership;
use Illuminate\Support\Collection;

/**
 * Who a task may be assigned to (S27 · PRD F4.10 · issue #71).
 *
 * One question with two readers — the modal's picker needs names, and the
 * request that saves the choice needs ids to validate against — so it is
 * asked once here. Two copies would be one copy that forgot a filter, and the
 * filter that would go missing is the one that matters.
 *
 * ## Why this is not "everybody in the directory"
 *
 * `tasks.assignee_id` points at `people`, which carries **no `team_id`** — it
 * holds credentials and nothing a team types (ADR 0002, and CLAUDE.md's note
 * on the hole the layers do not cover). So the global scope that protects
 * every other foreign key on the row protects nothing here: a person id from
 * another team would save happily, and a client's own `people` row would save
 * happily too.
 *
 * The team-shaped answer lives on `team_memberships`, which is scoped, so
 * that is what this asks:
 *
 * - **`carryingAccess()`** — the one definition of "works here" (#142). A
 *   client is a person the team knows, not somebody it can hand work to, and
 *   `people.view` is not what decides that.
 * - **`active()`** — a revoked colleague is not somebody to assign a deadline
 *   to. They keep the tasks already assigned to them, because the record of
 *   who owed the work is a fact about the past.
 *
 * That last distinction is why the picker and the *row* ask different
 * questions: this list is who may be **chosen**, and `TaskController` renders
 * a name for whoever is already there.
 */
final readonly class TaskAssignees
{
    /**
     * @return Collection<int, TeamMembership>
     */
    public function memberships(): Collection
    {
        return TeamMembership::query()
            ->carryingAccess()
            ->active()
            ->get(['id', 'person_id', 'first_name', 'last_name'])
            ->sortBy(fn (TeamMembership $membership): string => $membership->fullName())
            ->values();
    }

    /**
     * The `people` ids a task on this team may name, for validation.
     *
     * @return list<string>
     */
    public function personIds(): array
    {
        return array_values($this->memberships()
            ->map(fn (TeamMembership $membership): string => (string) $membership->person_id)
            ->unique()
            ->all());
    }
}
