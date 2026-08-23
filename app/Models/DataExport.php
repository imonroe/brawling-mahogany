<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A requested copy of a team's own data (PRD §9 · Screen Inventory S79).
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $requested_by_person_id
 * @property DataExportState $state
 * @property string|null $disk_path
 * @property int|null $size_bytes
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['requested_by_person_id', 'state', 'disk_path', 'size_bytes', 'expires_at', 'completed_at', 'error'])]
class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /** Long enough to notice the email, short enough that a stale link is not an archive. */
    public const LIFETIME_HOURS = 48;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DataExportState::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'requested_by_person_id');
    }

    public function isDownloadable(): bool
    {
        return $this->state === DataExportState::Ready
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
