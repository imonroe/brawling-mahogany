<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a team intends to happen (PRD §4.4 F4.1 · §7.1 · issue #64).
 *
 * The definition half of the split PRD §7.1 calls the highest-impact
 * correction in the document. Editing one of these must never change a deal
 * already running — `InstantiateWorkflow` (#66) snapshots at the moment of
 * instantiation, and nothing in the runtime layer reads back here.
 *
 * Not `BelongsToTeam`, for the same reason `roles` and `deal_types` are not: a
 * null `team_id` is a **system** template from a pack, shared by everybody,
 * and the global scope cannot express "mine or everybody's". `visibleTo()`
 * does, and `tests/Isolation/ModelTenancyConventionTest.php` carries the
 * reason.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string|null $template_pack_id
 * @property string $name
 * @property string|null $description
 * @property int $version
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'description', 'is_active'])]
class WorkflowTemplate extends Model
{
    /** @use HasFactory<WorkflowTemplateFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return BelongsTo<TemplatePack, $this>
     */
    public function templatePack(): BelongsTo
    {
        return $this->belongsTo(TemplatePack::class);
    }

    /**
     * @return HasMany<StageTemplate, $this>
     */
    public function stageTemplates(): HasMany
    {
        return $this->hasMany(StageTemplate::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<DealType, $this>
     */
    public function dealTypes(): BelongsToMany
    {
        return $this->belongsToMany(DealType::class, 'deal_type_workflow_template')
            ->withPivot('is_default');
    }

    /**
     * Runs produced by this template. Reporting only — see the class docblock.
     *
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function isSystem(): bool
    {
        return $this->team_id === null;
    }

    /**
     * How many of **this team's** live runs came from this template.
     *
     * S41 shows this before an edit — not to prevent one, because editing is
     * the point and the snapshot makes it safe, but so somebody changing a
     * workflow twelve deals came from knows the twelve will not change with it.
     *
     * Scoped, and the unscoped version was a leak of exactly the shape the
     * isolation suite exists to catch: a system template is shared by every
     * team, so counting without the scope would have told one team how many
     * deals every other team is running.
     */
    public function inUseCount(): int
    {
        return Workflow::query()
            ->where('workflow_template_id', $this->getKey())
            ->count();
    }

    /**
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
}
