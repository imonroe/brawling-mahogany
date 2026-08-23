<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A job a team intends to do in a stage (issue #64).
 *
 * `due_offset_days` is signed and relative to the stage start. Negative means
 * before it — *"order the survey three days before inspection opens"* is a real
 * line in Emily's list, and an unsigned offset could not express it.
 *
 * `is_required` is what feeds the `required_tasks_complete` gate, and it
 * defaults to false on purpose: most tasks are reminders, and a stage where
 * every task blocks is a stage nobody can leave.
 *
 * @property string $id
 * @property string $stage_template_id
 * @property string $title
 * @property string|null $description
 * @property string|null $owner_role
 * @property int|null $due_offset_days
 * @property bool $is_required
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['title', 'description', 'owner_role', 'due_offset_days', 'is_required', 'sort_order'])]
class TaskTemplate extends Model
{
    /** @use HasFactory<TaskTemplateFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StageTemplate, $this>
     */
    public function stageTemplate(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class);
    }
}
