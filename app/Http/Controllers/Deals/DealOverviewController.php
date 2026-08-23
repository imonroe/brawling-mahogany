<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\Stage;
use App\Models\Workflow;
use App\Queries\PropertyDirectory;
use App\Support\Activity\ActorDirectory;
use App\Support\Deals\DealHeader;
use App\Support\Workflow\DescribeBlockers;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The deal overview (Screen Inventory S15 · PRD §4.3 F3.7 · issue #75).
 *
 * ## The standard the screen is held to
 *
 * Issue #75: *"If a user has to scroll or click to learn what is blocking the
 * deal, the screen has failed."* So every unmet gate on every running
 * workflow's current stage is in this payload, each with the sentence its own
 * evaluator wrote and the link target that clears it — not a count, and not
 * the first one. `AdvanceResult`'s docblock argues the same point for the
 * refusal after the click; this is that argument applied before it.
 *
 * ## Six kinds of information (F3.7)
 *
 * Current stage, what blocks advance, upcoming dates, recent activity,
 * participants, documents. Four of the six have data in Slice 2. Dates are
 * Slice 4 (#109) and documents are Slice 3 (#104); #75 asks for their sections
 * to be *"first-class parts of the layout that state what will go there"*, so
 * they are laid out by `Deals/Overview.vue` and carry no props from here —
 * there is nothing yet to prop.
 *
 * ## One card per workflow
 *
 * PRD §7.5 gives a deal concurrent workflows on purpose. Every one of them
 * appears, running or not; only a running one with a stage to leave is offered
 * an Advance. `App\Support\Deals\DealHeader` settles what §8.4's single
 * primary button does when two are running, and says why.
 */
class DealOverviewController extends Controller
{
    /** How many participants fit the §9.2 rail card before it overflows. */
    private const PEOPLE_SHOWN = 4;

    /** Recent, not the whole timeline — S16 is the tab that shows all of it. */
    private const ACTIVITY_SHOWN = 8;

    public function show(Deal $deal, DescribeBlockers $blockers): Response
    {
        $this->authorize('view', $deal);

        /*
         * Everything the screen reads, in one pass.
         *
         * `DealHeader::for()` loads the same relations with `loadMissing`, so
         * naming them here costs nothing and keeps the eager-load list of this
         * screen readable in one place. `stages.gates` is the one the header
         * does not need and this screen cannot do without.
         */
        $deal->load([
            'dealType',
            'participants.membership',
            'propertyLinks.property',
            'workflows.stages.gates',
        ]);

        $subject = $deal->propertyLinks->firstWhere('is_subject', true);

        return Inertia::render('Deals/Overview', [
            'dealHeader' => DealHeader::for($deal),
            'workflows' => $deal->workflows
                ->map(fn (Workflow $workflow): array => $this->describeWorkflow($workflow, $deal, $blockers))
                ->values()
                ->all(),
            'subjectProperty' => $subject instanceof DealProperty && $subject->property !== null
                ? [
                    'id' => $subject->property->getKey(),
                    'name' => $subject->property->displayName(),
                    'address' => PropertyDirectory::address($subject->property),
                    'status' => $subject->property->status->value,
                ]
                : null,
            'candidateCount' => $deal->propertyLinks
                ->reject(fn (DealProperty $link): bool => $link->is_subject)
                ->count(),
            'participants' => $deal->participants
                ->take(self::PEOPLE_SHOWN)
                ->map(fn (DealParticipant $participant): array => [
                    'id' => $participant->getKey(),
                    'name' => $participant->fullName(),
                    'roleLabel' => $participant->participant_role->label(),
                    'isPrimary' => $participant->is_primary,
                ])->values()->all(),
            'participantCount' => $deal->participants->count(),
            'activity' => $this->recentActivity($deal),
        ]);
    }

    /**
     * One workflow, where it has got to, and what is stopping it.
     *
     * @return array<string, mixed>
     */
    private function describeWorkflow(Workflow $workflow, Deal $deal, DescribeBlockers $blockers): array
    {
        /*
         * The relation is loaded, so this reads it rather than querying — see
         * `Workflow::activeStage()`. Null on a completed, cancelled or
         * not-yet-started workflow, which is a card without an Advance rather
         * than a card that is missing.
         */
        $stage = $workflow->activeStage();

        /*
         * The inverse links, filled in from the graph already in memory.
         *
         * `field_populated` walks `$gate->stage->workflow->deal` and
         * `required_tasks_complete` walks `$gate->stage`. Left unset, each of
         * those is a query per gate per render on the busiest screen in the
         * product. Same objects, so nothing can disagree with itself, and
         * nothing here writes.
         */
        if (! $workflow->relationLoaded('deal')) {
            $workflow->setRelation('deal', $deal);
        }

        foreach ($workflow->stages as $each) {
            if (! $each->relationLoaded('workflow')) {
                $each->setRelation('workflow', $workflow);
            }
        }

        $readiness = $stage instanceof Stage ? $blockers->forStage($stage) : null;

        $index = $stage instanceof Stage
            ? $workflow->stages->values()->search(fn (Stage $each): bool => $each->is($stage))
            : false;

        return [
            'id' => $workflow->getKey(),
            'name' => $workflow->name,
            'state' => $workflow->state->value,
            'isRunning' => $workflow->isRunning(),
            /*
             * The §9.2 progress strip. Every stage, in order, with the state
             * that colours it — the rail is the one part of this screen that
             * answers "how far along is this" without reading a word.
             */
            'stages' => $workflow->stages->map(fn (Stage $each): array => [
                'id' => $each->getKey(),
                'name' => $each->name,
                'state' => $each->state->value,
                'isCurrent' => $stage instanceof Stage && $each->is($stage),
            ])->values()->all(),
            'currentStage' => $stage instanceof Stage ? [
                'id' => $stage->getKey(),
                'name' => $stage->name,
                'state' => $stage->state->value,
                'description' => $stage->description,
                'plannedEnd' => $stage->planned_end?->toIso8601String(),
                'position' => is_int($index) ? $index + 1 : null,
                'total' => $workflow->stages->count(),
            ] : null,
            'gates' => $readiness?->toArray() ?? [],
            /*
             * Whether to offer the button, not a promise that it will work —
             * `StageReadiness::canAdvance()` says why the two differ.
             */
            'canAdvance' => $workflow->isRunning() && $readiness?->canAdvance() === true,
        ];
    }

    /**
     * Recent activity on this deal (F3.7).
     *
     * **Asked by `deal_id`, not by subject.** *What this happened to* and
     * *which deal it belongs on* are two different questions, and this card
     * wants the second one. Three of the event types it most needs are
     * subjected to something else: `AdvanceWorkflow` records against the
     * workflow, and F2.5 logs a contact against the **person** with the deal
     * as context. An earlier draft asked
     * `forSubjects([$deal, ...$deal->workflows])`, which covered the workflow
     * half by enumerating it and missed the contact entirely — so an entry a
     * team made from this deal's own People tab appeared on the person and in
     * the team feed, everywhere except the deal they attached it to.
     *
     * `ActivityEvent::forDeal()` is the scope that exists for this, and it is
     * one predicate on an indexed column rather than a list of subjects that
     * has to be kept in step with every new event type.
     *
     * **Actor names come from `ActorDirectory`, and this screen does not have
     * its own copy of that.** `Person::displayNameWithin()` reads the actor's
     * membership in the team, so calling it inside the map costs a membership
     * lookup per event — and `$event->team` costs a team lookup on top. That
     * is the exact shape the budget tests exist to catch, on a screen the
     * whole product routes through.
     *
     * This card had its own hand-rolled `whereIn` doing the same job, and the
     * two had already drifted: one reached past a soft-deleted membership and
     * the other did not, so the same person was named on the feed and
     * anonymous here. One resolver, so there is one answer.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(Deal $deal): array
    {
        $events = ActivityEvent::query()
            ->forDeal($deal)
            ->limit(self::ACTIVITY_SHOWN)
            ->get();

        $actors = ActorDirectory::for($events);

        return array_values($events
            ->map(fn (ActivityEvent $event): array => [
                'id' => $event->getKey(),
                'eventType' => $event->event_type,
                'summary' => $event->summary,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                /*
                 * Null when the actor was the system. The screen renders the
                 * sentence without an attribution rather than inventing
                 * "Unknown" — the summary already says what happened.
                 */
                'actorName' => $actors->nameOf($event),
            ])->all());
    }
}
