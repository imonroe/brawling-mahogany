<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\AutomationTrigger;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Messages\RenderedMessage;
use Database\Factories\ActionInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing an automation is doing, or did, on one deal (issue #92).
 *
 * Team-scoped, unlike the definition it came from, because a row here holds a
 * rendered message with a client's name in it. The migration argues the rest.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string|null $stage_id
 * @property string|null $action_definition_id
 * @property AutomationActionType $action_type
 * @property AutomationTrigger $trigger
 * @property string|null $message_template_id
 * @property array<string, mixed>|null $config
 * @property AutomationState $state
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $executed_at
 * @property array<string, mixed>|null $payload
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $message_key
 * @property string|null $provider_message_id
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([])]
class ActionInstance extends Model
{
    /** @use HasFactory<ActionInstanceFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => AutomationActionType::class,
            'trigger' => AutomationTrigger::class,
            'state' => AutomationState::class,
            'config' => 'array',
            'payload' => 'array',
            'scheduled_for' => 'datetime',
            'executed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * @return BelongsTo<MessageTemplate, $this>
     */
    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'approved_by');
    }

    /**
     * The words, as they stood when this was raised.
     *
     * Never re-rendered from the template: two instances from one template are
     * two messages to two people, and an approver's edit belongs to one of
     * them.
     */
    public function rendered(): RenderedMessage
    {
        $payload = $this->payload ?? [];

        return new RenderedMessage(
            subject: is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            bodyHtml: is_string($payload['bodyHtml'] ?? null) ? $payload['bodyHtml'] : null,
            bodyText: is_string($payload['bodyText'] ?? null) ? $payload['bodyText'] : '',
            unresolved: self::strings($payload['unresolved'] ?? []),
            unknown: self::strings($payload['unknown'] ?? []),
            malformed: self::strings($payload['malformed'] ?? []),
        );
    }

    /**
     * Where this would go, as addresses recorded at raise time.
     *
     * @return list<array{name: string, email: string}>
     */
    public function recipients(): array
    {
        $recipients = ($this->payload ?? [])['recipients'] ?? [];

        if (! is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $row): ?array => is_array($row)
                && is_string($row['email'] ?? null)
                && is_string($row['name'] ?? null)
                    ? ['name' => $row['name'], 'email' => $row['email']]
                    : null,
            $recipients,
        )));
    }

    /**
     * Whether this row has already been handed to a transport.
     *
     * Asked **before** the state, and that ordering is the idempotency
     * guarantee. It reads `message_key` — ours, written before the mailer is
     * called — and never `provider_message_id`, which is theirs and arrives
     * after they answer. The whole case this defends against is the send that
     * went out and never came back, and that row has no provider id at all;
     * asking for one would let the retry through on exactly the send that
     * already reached a client.
     */
    public function reachedTheProvider(): bool
    {
        return $this->message_key !== null;
    }

    public function isSendable(): bool
    {
        return $this->state === AutomationState::Pending && ! $this->reachedTheProvider();
    }

    public function needsApproval(): bool
    {
        return $this->state === AutomationState::AwaitingApproval;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('state', AutomationState::AwaitingApproval);
    }

    /**
     * Pending and due — what the scheduler picks up.
     *
     * A null `scheduled_for` is due immediately; those are dispatched at raise
     * time and this catches the ones a crash left behind.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        return $query->where('state', AutomationState::Pending)
            /*
             * And never one already handed to a transport. `pending` with a
             * `message_key` is what a send that crashed between the mailer
             * call and the state write leaves behind, and it is the exact row
             * the scheduler would otherwise pick up and send a second time.
             *
             * `ExecuteAction` refuses it as well — with a sentence saying
             * nobody knows whether it arrived — so the row does not sit here
             * unexplained. The refusal is what takes it out of `pending` and
             * therefore out of this scope; a sweep that kept handing over a
             * row already excluded by the `whereNull` above would be arguing
             * with itself.
             */
            ->whereNull('message_key')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $at));
    }

    /**
     * @param  array<int, mixed>|mixed  $values
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, is_string(...)))
            : [];
    }
}
