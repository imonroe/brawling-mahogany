<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Enums\ActivitySource;
use App\Enums\AutomationState;
use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Person;
use App\Support\Activity\RecordActivity;
use App\Support\Formatting\Format;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `key_dates` (PRD §4.8 F8.2 · issue #106).
 *
 * `RecordActivity` owns `activity_events` and `Notify` owns `notifications`
 * for the same reason this owns its table: writing a key date is never only
 * writing a key date. Moving mutual acceptance moves the inspection objection
 * deadline, and the loan commitment date behind that, and reschedules the
 * automation that emails the client about the appraisal — and a controller
 * that called `$keyDate->save()` would produce a correct row beside a calendar
 * that is now wrong in four places.
 *
 * ## Preview and apply are the same computation
 *
 * #106 asks for a cascade that is *"previewable before it is applied"*, and
 * the only way to make a preview honest is for it to be the thing that
 * happens. {@see self::preview()} and {@see self::edit()} both call
 * `KeyDateGraph::cascadeFrom()`; the second one persists what the first one
 * returned.
 *
 * ## The two writes are in one transaction, and the dispatch is outside it
 *
 * The boundary `AdvanceWorkflow::dispatchRaised()` established: rows inside,
 * jobs after the commit. A cascade reschedules `action_instances`, and an
 * instance rescheduled inside a transaction that then rolls back is a client
 * email queued for a date that never moved.
 */
final class SaveKeyDate
{
    public function __construct(
        private readonly RecordActivity $activity,
        private readonly KeyDateAutomations $automations,
    ) {}

    /**
     * A new date on a deal.
     *
     * A date being *added* cannot move anything that already exists — nothing
     * points at a row that did not exist a moment ago — so there is no cascade
     * here, only the computation of this row's own value from its anchor.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AnchorWouldLoop
     */
    public function add(Deal $deal, array $attributes, ?Person $actor = null): KeyDate
    {
        return $this->create($deal, $attributes, $actor, KeyDateSource::Manual, null);
    }

    /**
     * Add a date a person confirmed out of a document (#115, #116 · PRD §6.2).
     *
     * A second entry point rather than a `source` argument on {@see self::add()},
     * and the difference is not cosmetic. This one **requires the confirming
     * person**, because PRD §6.2's rule — *"nothing reaches `key_dates` or
     * `tasks` except through a confirmed row"* — is only enforceable if there
     * is no way to write an extracted date without saying who agreed to it.
     * An optional parameter would have made the rule optional.
     *
     * ## `confirmed_at` is stamped here, so `isPending()` is false for every
     * row this writes — and that is correct
     *
     * #106 shipped `source`/`confirmed_by`/`confirmed_at` on `key_dates`
     * ahead of this slice, and `KeyDate::isPending()` reads the pair. Under
     * PRD §6.2 as written, a proposal lives in `extracted_fields` and a
     * `key_dates` row is only ever created *by* a confirmation — so a pending
     * extracted key date is a state this product cannot produce.
     *
     * That is worth saying plainly rather than leaving somebody to discover
     * it: `isPending()` and `scopeConfirmed()` are defence in depth against a
     * later path that writes one, not descriptions of a state you will find in
     * the table. CLAUDE.md's *"a row nothing can reach is a rule nobody is
     * following"* cuts the other way here — the alternative reading, writing
     * pending rows and confirming them in place, would put unreviewed model
     * output in `key_dates`, which is the single thing §6.2 exists to forbid.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AnchorWouldLoop
     */
    public function addConfirmedExtraction(Deal $deal, array $attributes, Person $confirmedBy): KeyDate
    {
        return $this->create($deal, $attributes, $confirmedBy, KeyDateSource::Extracted, $confirmedBy);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AnchorWouldLoop
     */
    private function create(
        Deal $deal,
        array $attributes,
        ?Person $actor,
        KeyDateSource $source,
        ?Person $confirmedBy,
    ): KeyDate {
        $graph = KeyDateGraph::forDeal($deal);

        $keyDate = new KeyDate;

        $keyDate->forceFill([
            'team_id' => $deal->team_id,
            'deal_id' => $deal->getKey(),
            'source' => $source->value,
            'confirmed_by' => $confirmedBy?->getKey(),
            'confirmed_at' => $confirmedBy === null ? null : now(),
        ]);

        $this->applyAttributes($keyDate, $attributes, $graph);

        DB::transaction(function () use ($keyDate): void {
            $keyDate->save();
        });

        $this->activity->record(
            subject: $keyDate,
            eventType: 'key_date.added',
            summary: $keyDate->name.' set to '.Format::date($keyDate->date),
            source: ActivitySource::System,
            actor: $actor,
            payload: ['keyDateId' => $keyDate->getKey(), 'date' => $keyDate->date->toDateString()],
            teamId: $deal->team_id,
            deal: $deal,
        );

        $this->dispatch($this->automations->reschedule([$keyDate], $deal));

        return $keyDate;
    }

    /**
     * Edit a date, and everything downstream of it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AnchorWouldLoop
     */
    public function edit(KeyDate $date, array $attributes, ?Person $actor = null): CascadeResult
    {
        $deal = $date->deal;

        $graph = KeyDateGraph::forDeal($deal);

        $before = $date->date->startOfDay();

        /*
         * Applied to the *graph's* copy, so the cascade reads the edited row
         * rather than the one still in the database. `find()` returns the same
         * instance the graph holds, and `cascadeFrom()` reads `follows()` off
         * every row including this one — an edit that detaches a date has to
         * be visible to the walk that decides whether to follow it.
         */
        $subject = $graph->find((string) $date->getKey()) ?? $date;

        $this->applyAttributes($subject, $attributes, $graph);

        $moved = $graph->cascadeFrom($subject, $subject->date);

        DB::transaction(function () use ($subject, $moved): void {
            $subject->save();

            foreach ($moved as $change) {
                $change->keyDate->forceFill(['date' => $change->to->toDateString()])->save();
            }
        });

        $this->recordEdit($subject, $before, $moved, $actor);

        $this->dispatch($this->automations->reschedule(
            [$subject, ...array_map(static fn (DateChange $c): KeyDate => $c->keyDate, $moved)],
            $deal,
        ));

        return new CascadeResult($subject, $moved);
    }

    /**
     * What `edit()` would do, without doing it (S18's cascade preview).
     *
     * The graph is loaded and mutated in memory and thrown away. Nothing here
     * touches the database, which is what makes it safe to call on a keystroke
     * — and is why the models it mutates are the graph's copies rather than
     * the caller's: a preview that left a caller holding a modified row would
     * be a preview with a side effect.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<DateChange>
     *
     * @throws AnchorWouldLoop
     */
    public function preview(KeyDate $date, array $attributes): array
    {
        $graph = KeyDateGraph::forDeal($date->deal);

        $subject = $graph->find((string) $date->getKey());

        if (! $subject instanceof KeyDate) {
            return [];
        }

        $this->applyAttributes($subject, $attributes, $graph);

        return $graph->cascadeFrom($subject, $subject->date);
    }

    /**
     * Delete a date.
     *
     * ## What happens to the dates derived from it
     *
     * They **stay where they are**, detached, and the loop below is what does
     * it. That is worth stating plainly, because the obvious reading is that
     * the composite foreign key's `ON DELETE SET NULL` handles it: a delete
     * here is a **soft** delete, so the FK never fires. It fires later, when
     * `records:purge` force-deletes the row thirty days on, and it is written
     * naming the column explicitly so the child's own `team_id` is not nulled
     * along with the anchor — a composite `SET NULL` blanks every referencing
     * column unless told otherwise.
     *
     * So the two are a belt and braces with different timing, and only one of
     * them is reachable from a screen — and the loop is what **makes** the
     * brace reachable, by clearing `is_derived`. The CHECK is evaluated on the
     * UPDATE that `SET NULL` performs, so a row still flagged derived is one
     * the database cannot detach at all.
     *
     * Deleting the objection deadline's anchor must not delete the objection
     * deadline: the obligation in the contract did not go away because
     * somebody tidied up the calendar. So the value it last had is what it
     * keeps — and it **keeps its anchor id and its offset too**, which is how
     * S18 can say *"was ten calendar days after another date, which has since
     * been removed"* rather than presenting a day the team never typed. The
     * removed anchor is deliberately not named: it does not exist any more,
     * and naming it would only send somebody looking for it.
     */
    public function remove(KeyDate $keyDate, ?Person $actor = null): void
    {
        $deal = $keyDate->deal;

        $dependents = KeyDate::query()
            ->where('anchor_key_date_id', $keyDate->getKey())
            ->get();

        DB::transaction(function () use ($keyDate, $dependents): void {
            foreach ($dependents as $dependent) {
                /*
                 * `is_derived` and `detached_at` only. The anchor id and the
                 * offset **stay**, and both halves of that matter.
                 *
                 * They are what the date has to say about itself: nulling them
                 * left *Inspection objection* indistinguishable from a day
                 * somebody typed, which is the exact sentence the PRD entry
                 * for this behaviour says must not be true. Keeping them lets
                 * S18 say it used to follow something, and how far behind it
                 * ran.
                 *
                 * And they are what makes the composite FK's `ON DELETE SET
                 * NULL` reachable. The CHECK refuses a row that is derived and
                 * missing its anchor, and `SET NULL` is an UPDATE the CHECK is
                 * evaluated on — so a row still flagged derived cannot be
                 * detached by the database at all: the force-delete raises
                 * 23514 and takes the team's nightly purge with it. Clearing
                 * the flag here is what leaves the FK a row it is allowed to
                 * touch, thirty days later.
                 */
                $dependent->forceFill([
                    'is_derived' => false,
                    'detached_at' => now(),
                ])->save();
            }

            $keyDate->delete();
        });

        $this->activity->record(
            subject: $deal,
            eventType: 'key_date.removed',
            summary: $keyDate->name.' removed from Dates & Deadlines',
            source: ActivitySource::System,
            actor: $actor,
            payload: ['keyDateId' => $keyDate->getKey(), 'name' => $keyDate->name],
            teamId: $keyDate->team_id,
            deal: $deal,
        );

        $this->automations->cancelFor($keyDate);
    }

    /**
     * Queue what the reschedule raised, after the write has committed.
     *
     * The boundary `AdvanceWorkflow::dispatchRaised()` established, and the
     * same two rules: only `pending` rows go — one that opened in
     * `awaiting_approval` is released by `ApproveMessage` and by nothing else,
     * and dispatching it here would send the message F5.7's queue exists to
     * hold — and nothing is dispatched from inside a transaction, because a
     * worker that picks the job up before the commit lands finds no row.
     *
     * @param  list<ActionInstance>  $raised
     */
    private function dispatch(array $raised): void
    {
        foreach ($raised as $instance) {
            if ($instance->state === AutomationState::Pending) {
                dispatch((new RunAutomation($instance->getKey()))->forTeam($instance->team_id));
            }
        }
    }

    /**
     * Read one edit onto a row, deciding derivation as it goes.
     *
     * ## Typing a date over a derived one detaches it
     *
     * #106: *"a derived date that has been manually overridden stops following
     * its anchor, and says so."* Detaching is the **absence** of an anchor in
     * the payload plus the presence of a date — a form that submits both is
     * saying "derive it" and wins, because that is the only reading under
     * which an editor can re-attach a date it previously detached.
     *
     * The anchor and the offset are kept on the row when it detaches. Clearing
     * them would lose the *and says so*: S18 can only tell somebody this date
     * used to be ten days after mutual acceptance if the row still remembers.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AnchorWouldLoop
     */
    private function applyAttributes(KeyDate $date, array $attributes, KeyDateGraph $graph): void
    {
        if (array_key_exists('name', $attributes)) {
            $date->name = trim((string) $attributes['name']);
        }

        if (array_key_exists('is_critical', $attributes)) {
            $date->is_critical = (bool) $attributes['is_critical'];
        }

        if (array_key_exists('notes', $attributes)) {
            $notes = $attributes['notes'];

            $date->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        }

        if (array_key_exists('reminder_offsets', $attributes)) {
            $date->reminder_offsets = $this->reminderOffsets($attributes['reminder_offsets']);
        }

        $anchorId = array_key_exists('anchor_key_date_id', $attributes)
            ? ($attributes['anchor_key_date_id'] === null ? null : (string) $attributes['anchor_key_date_id'])
            : null;

        if ($anchorId !== null) {
            $anchor = $graph->find($anchorId);

            if ($graph->wouldLoop($date, $anchorId)) {
                throw AnchorWouldLoop::at($anchor instanceof KeyDate ? $anchor->name : 'that date');
            }

            if ($anchor instanceof KeyDate) {
                $basis = OffsetBasis::tryFrom((string) ($attributes['offset_basis'] ?? ''))
                    ?? OffsetBasis::Calendar;

                $date->offset_days = (int) ($attributes['offset_days'] ?? 0);
                $date->offset_basis = $basis;

                /*
                 * `forceFill`, because `date` and `detached_at` are casts and
                 * the values here are days rather than instants — assigning
                 * through the magic property would put a `CarbonImmutable`
                 * where the cast expects to do the conversion, and the two
                 * disagree about the time of day.
                 */
                $date->forceFill([
                    'anchor_key_date_id' => $anchorId,
                    'is_derived' => true,
                    'detached_at' => null,
                    'date' => $date->derivedFrom($anchor->date)->toDateString(),
                ]);

                return;
            }

            /*
             * An anchor id that resolves to nothing in this deal's graph is a
             * caller error, and falling through to the typed-date branch is
             * the wrong way to report it: a *derived* payload would save as a
             * plain date, with no anchor, no offset and nothing on the screen
             * to say the request was not honoured.
             *
             * `SaveKeyDateRequest::onThisDeal()` refuses it first for every
             * HTTP caller, so this is reachable only from a caller that has
             * not been written yet — F5.3's automation, an importer, Slice
             * 5's extraction. Which is exactly when a silent fall-through is
             * most expensive: nobody is watching a screen.
             */
            throw UnknownAnchor::id($anchorId);
        }

        if (array_key_exists('date', $attributes) && $attributes['date'] !== null) {
            /*
             * A date typed onto a row that was following an anchor stops it
             * following. `wasDetached()` is what S18 reads to say so, and
             * `detached_at` is when.
             */
            $detaching = $date->is_derived
                ? ['is_derived' => false, 'detached_at' => now()]
                : [];

            $date->forceFill([
                ...$detaching,
                'date' => $this->day($attributes['date'])->toDateString(),
            ]);
        }
    }

    /**
     * @param  list<DateChange>  $moved
     */
    private function recordEdit(KeyDate $keyDate, CarbonInterface $before, array $moved, ?Person $actor): void
    {
        $deal = $keyDate->deal;

        if ($before->toDateString() !== $keyDate->date->toDateString()) {
            $this->activity->record(
                subject: $keyDate,
                eventType: 'key_date.moved',
                summary: $keyDate->name.' moved from '.Format::date($before).' to '.Format::date($keyDate->date),
                source: ActivitySource::System,
                actor: $actor,
                payload: [
                    'keyDateId' => $keyDate->getKey(),
                    'from' => $before->toDateString(),
                    'to' => $keyDate->date->toDateString(),
                ],
                teamId: $keyDate->team_id,
                deal: $deal,
            );
        }

        if ($moved === []) {
            return;
        }

        /*
         * **One entry for the whole cascade, not one per date.**
         *
         * The lesson `NotificationFeed` records about folding, arriving at a
         * second table: eleven rows on a deal timeline for one edit is eleven
         * rows nobody reads, and the fact somebody wants six weeks later is
         * *"moving closing moved eleven dates"* — which is a single sentence.
         * The dates themselves are in the payload for anything that needs the
         * detail.
         */
        $this->activity->record(
            subject: $keyDate,
            eventType: 'key_date.cascaded',
            summary: count($moved) === 1
                ? '1 other date moved with '.$keyDate->name
                : count($moved).' other dates moved with '.$keyDate->name,
            source: ActivitySource::System,
            actor: $actor,
            payload: [
                'keyDateId' => $keyDate->getKey(),
                'moved' => array_map(static fn (DateChange $c): array => $c->toArray(), $moved),
            ],
            teamId: $keyDate->team_id,
            deal: $deal,
        );
    }

    /**
     * A whole day, whatever the caller handed over.
     *
     * `startOfDay()` because a `date` column that is fed an instant is a day
     * that changes meaning when somebody reads it in another timezone — the
     * defect `offers` records under its `_on` naming rule.
     */
    private function day(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->startOfDay();
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }

    /**
     * @return list<int>|null
     */
    private function reminderOffsets(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $days = [];

        foreach ($value as $day) {
            if (is_numeric($day) && (int) $day >= 0) {
                $days[] = (int) $day;
            }
        }

        $days = array_values(array_unique($days));

        rsort($days);

        return $days;
    }
}
