<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Task;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class TaskPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Task $task): bool
    {
        return $this->belongsToCurrentTeam($task)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, Task $task): bool
    {
        return $this->belongsToCurrentTeam($task)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function delete(Person $person, Task $task): bool
    {
        return $this->update($person, $task);
    }
}
