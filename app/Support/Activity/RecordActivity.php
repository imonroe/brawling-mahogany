<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The one service that writes the timeline (PRD §4.9 F9.4, §7.7).
 *
 * Issue #50 is specific about this: *"Everything, through one recording
 * service — never by controllers scattering `create()` calls."* The reason is
 * `is_client_visible`. Twenty call sites is twenty chances to leave an
 * internal note visible to a client, and the flag defaults to false here, once,
 * where it can be tested.
 *
 * IA §11 calls what this writes **Activity** — never History, Log, Feed, or
 * Audit. *Audit* is the security log, and that has its own service.
 */
final class RecordActivity
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Deal|null  $deal  The deal this belongs on when the subject is
     *                           not itself a deal — a logged contact against a
     *                           client (PRD F2.5), or a stage advance whose
     *                           subject is the workflow.
     */
    public function record(
        Model $subject,
        string $eventType,
        string $summary,
        ActivitySource $source = ActivitySource::System,
        ?Person $actor = null,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        bool $isClientVisible = false,
        ?string $teamId = null,
        ?Deal $deal = null,
    ): ActivityEvent {
        $attributes = [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            /*
             * Derived from the subject when the subject *is* a deal, rather
             * than left to each caller.
             *
             * Seven of the nine deal-context call sites pass the deal as the
             * subject already, and asking each of them to repeat it is the
             * shape of rule that gets written into one caller and forgotten in
             * the next one somebody adds.
             */
            'deal_id' => $deal?->getKey() ?? ($subject instanceof Deal ? $subject->getKey() : null),
            'actor_person_id' => $actor?->getKey() ?? $this->currentActorId(),
            'event_type' => $eventType,
            'source' => $source->value,
            'occurred_at' => $occurredAt ?? now(),
            'summary' => $summary,
            'payload' => $payload,
            // The default that matters. An event is internal unless somebody
            // deliberately says otherwise (issue #50).
            'is_client_visible' => $isClientVisible,
        ];

        if ($teamId !== null) {
            $attributes['team_id'] = $teamId;
        }

        return ActivityEvent::query()->create($attributes);
    }

    private function currentActorId(): ?string
    {
        $person = auth()->user();

        return $person instanceof Person ? $person->getKey() : null;
    }
}
