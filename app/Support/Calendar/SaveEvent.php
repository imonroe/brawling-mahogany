<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\ActivitySource;
use App\Models\Deal;
use App\Models\Event;
use App\Models\Person;
use App\Models\Stage;
use App\Support\Activity\RecordActivity;
use App\Support\Formatting\Format;

/**
 * The only writer of `events` (PRD §4.8 F8.1 · issue #105).
 *
 * The pattern `DealTasks` and `SaveKeyDate` set. Not because an event is
 * complicated — it is the simplest row in Slice 4 — but because of what has to
 * happen *beside* the write: an event linked to a deal belongs on that deal's
 * timeline, and a rule written into one caller is a rule the next caller is
 * written without. F5.3's *create calendar event* action is that next caller,
 * and it arrives from a queue worker with no request behind it.
 *
 * ## Only a deal-linked event reaches a timeline
 *
 * `activity_events.deal_id` is *where a team looks for it*, and an open house
 * with no deal has nowhere to be looked for. Recording one against the
 * property would put it on a screen nobody opens to ask "what happened this
 * week", so it is recorded nowhere and the calendar is the record — which is
 * the honest answer rather than an entry written to look thorough.
 */
final class SaveEvent
{
    public function __construct(private readonly RecordActivity $activity) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(array $attributes, ?Person $actor = null, ?Stage $stage = null): Event
    {
        $event = new Event;

        $event->forceFill([
            ...$attributes,
            'stage_id' => $stage?->getKey(),
        ])->save();

        $this->record($event, 'event.added', 'Added '.$this->phrase($event), $actor);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function edit(Event $event, array $attributes, ?Person $actor = null): Event
    {
        $before = $event->starts_at;
        /*
         * The **id**, not the relation.
         *
         * Reading `$event->deal` here would load and cache it, and the cached
         * instance survives the `forceFill` below — so a comparison against
         * `$event->deal` afterwards would be comparing the old deal with
         * itself and could never see a change. A column is the honest thing to
         * hold across a write.
         */
        $wasOnId = $event->deal_id;

        $event->forceFill($attributes)->save();

        // The pointer moved, so anything the relation had cached is a lie.
        $event->unsetRelation('deal');

        /*
         * Two different entries, because they answer different questions. A
         * **moved** event is the one somebody chases six weeks later — *"when
         * did the inspection get pushed?"* — and burying it under a generic
         * "edited" is how that answer stops being findable.
         */
        $moved = $before->ne($event->starts_at);

        $this->record(
            $event,
            $moved ? 'event.moved' : 'event.edited',
            $moved
                ? $event->title.' moved to '.Format::date($event->starts_at)
                : 'Edited '.$this->phrase($event),
            $actor,
        );

        /*
         * An event that has **left** a deal says so on the deal it left.
         *
         * The edit above records against wherever the event is now, which is
         * right and is not the whole answer: an inspection taken off a deal
         * disappears from that deal's calendar, and a timeline that only ever
         * gained entries would leave somebody looking for an appointment that
         * is no longer there with nothing to read.
         *
         * Only when the pointer actually changed — an ordinary edit that keeps
         * the deal must not write a removal beside its own entry.
         */
        /*
         * Named `$deal` rather than `$wasOn`, and not only for readability:
         * `ActivityFeedIsolationTest` resolves a `subject:` argument to a
         * class **by the variable's own name**, so a local named for what it
         * holds is what keeps the cross-tenant guard able to see this call at
         * all. A cleverer name makes the row invisible to the check that
         * decides who may read it.
         */
        $deal = $wasOnId === null || $wasOnId === $event->deal_id
            ? null
            : Deal::query()->whereKey($wasOnId)->first();

        if ($deal instanceof Deal) {
            $this->activity->record(
                subject: $deal,
                eventType: 'event.removed',
                summary: $event->title.' is no longer on this deal',
                source: ActivitySource::System,
                actor: $actor,
                payload: ['eventId' => $event->getKey()],
                teamId: $event->team_id,
                deal: $deal,
            );
        }

        return $event;
    }

    public function remove(Event $event, ?Person $actor = null): void
    {
        $title = $event->title;
        $deal = $event->deal;

        $event->delete();

        if (! $deal instanceof Deal) {
            return;
        }

        $this->activity->record(
            subject: $deal,
            eventType: 'event.removed',
            summary: $title.' was removed from the calendar',
            source: ActivitySource::System,
            actor: $actor,
            teamId: $event->team_id,
            deal: $deal,
        );
    }

    private function record(Event $event, string $type, string $summary, ?Person $actor): void
    {
        $deal = $event->deal;

        if (! $deal instanceof Deal) {
            return;
        }

        $this->activity->record(
            subject: $deal,
            eventType: $type,
            summary: $summary,
            source: ActivitySource::System,
            actor: $actor,
            payload: ['eventId' => $event->getKey()],
            teamId: $event->team_id,
            deal: $deal,
        );
    }

    private function phrase(Event $event): string
    {
        return mb_strtolower($event->type->label()).' “'.$event->title.'” on '.Format::date($event->starts_at);
    }
}
