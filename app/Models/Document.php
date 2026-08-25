<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
 * @property string|null $uploaded_by
 */
#[Fillable(['caption'])]
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
     * @return BelongsTo<Person, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'uploaded_by');
    }
}
