<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One entry on the unified timeline (PRD §4.9 F9.4, §6.2, §7.7 · IA §2).
 *
 * Nothing creates one of these directly. Everything goes through
 * App\Support\Activity\RecordActivity, because a timeline written by twenty
 * scattered `create()` calls is twenty chances to forget `is_client_visible`
 * — and that flag is the boundary between an internal note and something a
 * client reads.
 *
 * @property string $id
 * @property string $team_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $actor_person_id
 * @property string $event_type
 * @property string $source
 * @property Carbon $occurred_at
 * @property string $summary
 * @property array<string, mixed>|null $payload
 * @property bool $is_client_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'actor_person_id',
    'event_type',
    'source',
    'occurred_at',
    'summary',
    'payload',
    'is_client_visible',
])]
class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'is_client_visible' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(type: 'subject_type', id: 'subject_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'actor_person_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('occurred_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClientVisible(Builder $query): Builder
    {
        return $query->where('is_client_visible', true);
    }
}
