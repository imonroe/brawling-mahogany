<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactImportSource;
use App\Enums\ContactImportState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\ContactImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact import attempt (PRD §4.2 F2.8 · Screen Inventory S33).
 *
 * The row exists because the import is a queued job: *"a 2,000-row Google
 * Contacts import cannot run in a web request."* The screen polls this record
 * for progress, and reads `failures` afterwards to tell somebody that row 340
 * was the problem rather than that the import failed.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $requested_by_person_id
 * @property ContactImportSource $source
 * @property ContactImportState $state
 * @property string|null $original_filename
 * @property string|null $disk_path
 * @property array<string, string>|null $column_mapping
 * @property array<int, array<string, mixed>>|null $preview
 * @property array<string, int>|null $summary
 * @property array<int, array<string, mixed>>|null $failures
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'requested_by_person_id',
    'source',
    'state',
    'original_filename',
    'disk_path',
    'column_mapping',
    'preview',
    'summary',
    'failures',
    'completed_at',
])]
class ContactImport extends Model
{
    /** @use HasFactory<ContactImportFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ContactImportSource::class,
            'state' => ContactImportState::class,
            'column_mapping' => 'array',
            'preview' => 'array',
            'summary' => 'array',
            'failures' => 'array',
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
}
