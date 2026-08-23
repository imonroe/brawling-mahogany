<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\GateTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A condition a team intends to require (issue #64).
 *
 * `gate_type` resolves to an evaluator class through the registry (#67), which
 * is what makes gate types data rather than code. PRD §7.8 on the alternative:
 * *"Without a typed gate model, 'gates' becomes a hand-written conditional per
 * stage and templates cannot be user-editable."*
 *
 * @property string $id
 * @property string $stage_template_id
 * @property string $gate_type
 * @property string $label
 * @property array<string, mixed>|null $config
 * @property bool $is_blocking
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['gate_type', 'label', 'config', 'is_blocking', 'sort_order'])]
class GateTemplate extends Model
{
    /** @use HasFactory<GateTemplateFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_blocking' => 'boolean',
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
