<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealSide;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DealTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What kind of transaction this is (PRD §4.3 F3.1, §7.6 · S76 · issue #58).
 *
 * A lookup, not a business table, and the distinction is why this carries no
 * `BelongsToTeam`. A null `team_id` is a **system default** every team gets;
 * a set one is a type a team wrote for itself. The global scope cannot express
 * "mine or everybody's", so `visibleTo()` does, and
 * `tests/Isolation/ModelTenancyConventionTest.php` carries the reason.
 *
 * ## Archived, never deleted
 *
 * S76's in-use warning is the pattern for every lookup in this product: you
 * cannot silently remove a value that live records point at. Deleting "Rental
 * Placement" would orphan every rental deal that ever used it. Archiving keeps
 * those deals labelled and takes the type out of every picker, which is what
 * somebody actually means when they try to delete one.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $name
 * @property DealSide $side
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'side', 'sort_order'])]
class DealType extends Model
{
    /** @use HasFactory<DealTypeFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => DealSide::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Which workflow templates this type offers (F4.1).
     *
     * @return BelongsToMany<WorkflowTemplate, $this>
     */
    public function workflowTemplates(): BelongsToMany
    {
        return $this->belongsToMany(WorkflowTemplate::class, 'deal_type_workflow_template')
            ->withPivot('is_default');
    }

    /** A system default, shared by every team. */
    public function isSystem(): bool
    {
        return $this->team_id === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Types this team may choose from: its own, plus the system defaults.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, Team|string $team): Builder
    {
        $teamId = $team instanceof Team ? $team->getKey() : $team;

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('team_id')
            ->orWhere('team_id', $teamId));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Can this type be archived, or is something still standing on it?
     *
     * A system default is never archivable by a team — it is not theirs, and
     * hiding it for everybody because one team stopped doing rentals is not
     * what they asked for. (Taking a system type out of *one* team's picker is
     * a real want and a different feature; S76 does not have it yet.)
     */
    public function isArchivable(): bool
    {
        return ! $this->isSystem() && ! $this->isArchived();
    }

    /**
     * Live deals **this team** has on this type — what S76's warning counts.
     *
     * Scoped, and the unscoped version was a leak. A system deal type is
     * shared by every team on the platform, so counting without the scope
     * would have answered "how many deals does everybody have" and shown that
     * number to one team. It is also the wrong question: a team deciding
     * whether to archive a type means "am *I* still using this".
     */
    public function liveDealCount(): int
    {
        return Deal::query()
            ->where('deal_type_id', $this->getKey())
            ->count();
    }
}
