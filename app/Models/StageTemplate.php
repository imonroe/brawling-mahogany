<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\StageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A period a team expects to work through (issue #64).
 *
 * `owner_role` is a role and never a person. A template naming Heather breaks
 * the moment a team has a different assistant, and packs ship between teams
 * where naming anybody at all is meaningless. Resolution to an actual human
 * happens once, at instantiation (#66).
 *
 * @property string $id
 * @property string $workflow_template_id
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property int|null $expected_duration_days
 * @property string|null $owner_role
 * @property bool $is_milestone
 * @property string|null $client_facing_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'name', 'description', 'sort_order', 'expected_duration_days',
    'owner_role', 'is_milestone', 'client_facing_label',
])]
class StageTemplate extends Model
{
    /** @use HasFactory<StageTemplateFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_milestone' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * @return HasMany<GateTemplate, $this>
     */
    public function gateTemplates(): HasMany
    {
        return $this->hasMany(GateTemplate::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<TaskTemplate, $this>
     */
    public function taskTemplates(): HasMany
    {
        return $this->hasMany(TaskTemplate::class)->orderBy('sort_order');
    }
}
