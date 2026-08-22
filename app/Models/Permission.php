<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One capability the product exposes (PRD §6.2).
 *
 * *"Flat, seeded in code."* There is no per-team catalogue and no UI for
 * inventing one: teams compose roles out of these, they do not invent
 * capabilities. The authoritative list is App\Support\Permissions.
 *
 * No soft deletes: a permission that no longer exists in code should leave the
 * table rather than linger as a tombstone a role could still point at.
 *
 * @property string $id
 * @property string $key
 * @property string $group
 * @property string $description
 */
#[Fillable(['key', 'group', 'description'])]
class Permission extends Model
{
    use HasUlids;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
