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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->forSubjects([$subject]);
    }

    /**
     * The timeline of several records read as one (S15 · #75).
     *
     * A deal's readable history is not all recorded against the deal.
     * `PropertyDeals` and `DealRoster` write against the deal;
     * `InstantiateWorkflow` and `AdvanceWorkflow` write against the
     * **workflow**, deliberately — a workflow's own timeline is a real thing
     * and #74's timeline tab (S16) will want it on its own. So the deal
     * overview asks for the deal *and* its workflows, and the alternative
     * would have been an activity card that never mentioned an advance.
     *
     * One query whatever the subjects, and grouped by morph class rather than
     * matched pairwise, so a deal with twelve workflows costs the same `IN`
     * that one workflow costs.
     *
     * An empty list matches nothing rather than everything. A screen that asks
     * about no subjects wants no rows, and the fail-open reading here would be
     * every event in the team on a page that renders eight of them.
     *
     * @param  Builder<self>  $query
     * @param  iterable<Model>  $subjects
     * @return Builder<self>
     */
    public function scopeForSubjects(Builder $query, iterable $subjects): Builder
    {
        /** @var array<string, list<mixed>> $byType */
        $byType = [];

        foreach ($subjects as $subject) {
            $byType[$subject->getMorphClass()][] = $subject->getKey();
        }

        if ($byType === []) {
            return $query->whereRaw('1 = 0')->orderByDesc('occurred_at');
        }

        return $query
            ->where(function (Builder $scoped) use ($byType): void {
                foreach ($byType as $type => $ids) {
                    $scoped->orWhere(fn (Builder $pair) => $pair
                        ->where('subject_type', $type)
                        ->whereIn('subject_id', $ids));
                }
            })
            ->orderByDesc('occurred_at');
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
