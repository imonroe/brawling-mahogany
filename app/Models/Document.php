<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded file (PRD §4.6 F6.4–F6.6, §7.14 · S38 · issue #63).
 *
 * **Nothing here can make a file public.** There is no `url()`, no
 * `getUrlAttribute`, and no accessor that returns anything a browser could
 * fetch — F6.4's *"no public buckets, every download authorized and
 * short-lived"* is a property of there being exactly one way to read a file,
 * and that way is `DocumentDownloadController`, which checks the policy and
 * writes the audit entry first.
 *
 * `path`, `disk`, `size_bytes` and `mime_type` are **not fillable**: they are
 * facts about a file the storage service established, not fields a request
 * body may assert. A request that could choose its own `path` could read
 * another team's.
 *
 * @property string $team_id
 * @property string $documentable_type
 * @property string $documentable_id
 * @property DocumentCategory $category
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $caption
 * @property int $sort_order
 * @property bool $is_primary
 * @property DocumentVisibility $visibility
 * @property string|null $scan_state
 * @property Carbon|null $scanned_at
 * @property string|null $uploaded_by
 */
#[Fillable(['caption', 'visibility'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'visibility' => DocumentVisibility::class,
            'scanned_at' => 'datetime',
            'is_primary' => 'boolean',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether nobody looked inside this file.
     *
     * Not the same question as *"is it safe"*, and the screens must not
     * collapse them: PRD §14.1 Q6's whole objection is that a scan implies a
     * guarantee it cannot give, and an image this build has no OCR for was
     * never examined at all. A row that says `not_scanned` is telling the
     * truth about a check that did not happen.
     */
    public function wasScanned(): bool
    {
        return $this->scan_state === 'clean';
    }

    public function isClientVisible(): bool
    {
        return $this->visibility->isClientVisible();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'uploaded_by');
    }
}
