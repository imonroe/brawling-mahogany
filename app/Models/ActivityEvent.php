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
 * @property string|null $deal_id
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
    'deal_id',
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
     * The deal this event belongs on, which is not always its subject.
     *
     * A logged contact's subject is the person; the deal is where the team
     * expects to find it (PRD F2.5, issue #81).
     *
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * One record's timeline — everything that happened *to* this thing.
     *
     * There used to be a `forSubjects()` beside this that took a list, added
     * for the deal overview so it could ask about a deal and its workflows at
     * once. The overview reads `deal_id` now (`forDeal()` below), because
     * *what this happened to* and *which deal it belongs on* are two different
     * questions and the card wanted the second — so the plural scope lost its
     * only caller, and with it the morph-class grouping and the empty-list
     * branch, each of which had a paragraph of docblock defending it and no
     * test anywhere.
     *
     * S16 may well want several subjects read as one. It can have the scope
     * back then, with the cases that hold it. Keeping an untested, uncalled
     * generalisation against that day is how the two branches came to be
     * described in terms of a caller that no longer exists.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /**
     * One deal's timeline (S16), which is not the same set as "events whose
     * subject is this deal".
     *
     * A logged contact against a client sits on the deal it was attached to
     * (PRD F2.5) while its subject stays the person, and a stage advance sits
     * on the deal while its subject stays the workflow. `deal_id` is the one
     * column that answers the question either way, which is why
     * `RecordActivity` fills it from the subject when the subject *is* a deal
     * rather than leaving each caller to remember.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDeal(Builder $query, Deal $deal): Builder
    {
        return $query
            ->where('deal_id', $deal->getKey())
            ->orderByDesc('occurred_at')
            /*
             * `occurred_at` is `timestamp(0)`, and one `AdvanceWorkflow::handle()`
             * writes `stage.advanced`, `milestone.reached` and
             * `workflow.completed` inside the same second. Without a tiebreak
             * their order — and which of them survives a `limit()` at the
             * boundary — is whatever Postgres happens to return.
             * `ActivityFeed::paginate()` already learned this; the scopes S16
             * will inherit had not.
             */
            ->orderByDesc('id');
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
