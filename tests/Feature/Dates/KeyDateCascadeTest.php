<?php

declare(strict_types=1);

use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Support\Dates\AnchorWouldLoop;
use App\Support\Dates\KeyDateGraph;
use App\Support\Dates\SaveKeyDate;
use App\Support\Dates\UnknownAnchor;

/**
 * The contingency calendar's hard half (PRD §4.8 F8.2 · issue #106).
 *
 * PRD §7.9 calls this *"where the product earns its subscription"*, and #106
 * names the four things that have to be true: derived dates compute across
 * both bases, moving an anchor cascades **transitively**, the cascade is
 * previewable before it is applied, and a chain that loops is refused.
 *
 * The fifth is the one review keeps finding in features like this — that a
 * value somebody typed over stays typed over. A derived date the cascade still
 * dragged around would be an override in name only.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $this->save = app(SaveKeyDate::class);
});

/** Acceptance → objection (+10 calendar) → resolution (+3 calendar). */
function chainOfThree(Deal $deal): array
{
    $save = app(SaveKeyDate::class);

    $acceptance = $save->add($deal, ['name' => 'Mutual acceptance', 'date' => '2026-09-01']);

    $objection = $save->add($deal, [
        'name' => 'Inspection objection',
        'anchor_key_date_id' => $acceptance->getKey(),
        'offset_days' => 10,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]);

    $resolution = $save->add($deal, [
        'name' => 'Inspection resolution',
        'anchor_key_date_id' => $objection->getKey(),
        'offset_days' => 3,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]);

    return [$acceptance, $objection, $resolution];
}

it('computes a derived date from its anchor when it is created', function (): void {
    [, $objection] = chainOfThree($this->deal);

    expect($objection->date->toDateString())->toBe('2026-09-11')
        ->and($objection->is_derived)->toBeTrue()
        ->and($objection->follows())->toBeTrue();
});

it('cascades transitively when the anchor moves', function (): void {
    [$acceptance, $objection, $resolution] = chainOfThree($this->deal);

    // Three days later. Everything behind it should move three days too.
    $result = $this->save->edit($acceptance, ['date' => '2026-09-04']);

    expect($result->movedCount())->toBe(2)
        ->and($objection->refresh()->date->toDateString())->toBe('2026-09-14')
        ->and($resolution->refresh()->date->toDateString())->toBe('2026-09-17');
});

it('previews exactly what the apply will do, and writes nothing', function (): void {
    [$acceptance, $objection] = chainOfThree($this->deal);

    $preview = $this->save->preview($acceptance, ['date' => '2026-09-04']);

    expect($preview)->toHaveCount(2)
        ->and($preview[0]->keyDate->getKey())->toBe($objection->getKey())
        ->and($preview[0]->to->toDateString())->toBe('2026-09-14')
        // Nothing moved: a preview with a side effect is not a preview.
        ->and($objection->refresh()->date->toDateString())->toBe('2026-09-11')
        ->and($acceptance->refresh()->date->toDateString())->toBe('2026-09-01');

    $applied = $this->save->edit($acceptance, ['date' => '2026-09-04']);

    expect(array_map(fn ($change): array => $change->toArray(), $applied->moved))
        ->toBe(array_map(fn ($change): array => $change->toArray(), $preview));
});

it('leaves a date somebody typed over where they typed it', function (): void {
    [$acceptance, $objection, $resolution] = chainOfThree($this->deal);

    // Somebody overrides the middle of the chain.
    $this->save->edit($objection, ['date' => '2026-09-20']);

    expect($objection->refresh()->is_derived)->toBeFalse()
        ->and($objection->wasDetached())->toBeTrue()
        ->and($objection->detached_at)->not->toBeNull()
        // The anchor is remembered, so S18 can still say what it used to follow.
        ->and($objection->anchor_key_date_id)->toBe($acceptance->getKey());

    // Moving the anchor now moves nothing: the chain is cut at the override.
    $result = $this->save->edit($acceptance, ['date' => '2026-09-08']);

    expect($result->movedCount())->toBe(0)
        ->and($objection->refresh()->date->toDateString())->toBe('2026-09-20')
        ->and($resolution->refresh()->date->toDateString())->toBe('2026-09-23');
});

it('re-derives a detached date when somebody gives it an anchor again', function (): void {
    [$acceptance, $objection] = chainOfThree($this->deal);

    $this->save->edit($objection, ['date' => '2026-09-20']);

    $this->save->edit($objection, [
        'anchor_key_date_id' => $acceptance->getKey(),
        'offset_days' => 10,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]);

    expect($objection->refresh()->is_derived)->toBeTrue()
        ->and($objection->detached_at)->toBeNull()
        ->and($objection->date->toDateString())->toBe('2026-09-11');
});

it('refuses an anchor chain that loops', function (): void {
    [$acceptance, , $resolution] = chainOfThree($this->deal);

    // Acceptance ← resolution would close the circle acceptance → objection →
    // resolution → acceptance.
    expect(fn (): mixed => $this->save->edit($acceptance, [
        'anchor_key_date_id' => $resolution->getKey(),
        'offset_days' => 1,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]))->toThrow(AnchorWouldLoop::class);
});

it('refuses a date anchored to itself', function (): void {
    $date = $this->save->add($this->deal, ['name' => 'Closing', 'date' => '2026-10-01']);

    expect(fn (): mixed => $this->save->edit($date, [
        'anchor_key_date_id' => $date->getKey(),
        'offset_days' => 1,
        'offset_basis' => OffsetBasis::Calendar->value,
    ]))->toThrow(AnchorWouldLoop::class);
});

it('offers only the anchors that would not loop', function (): void {
    [$acceptance, $objection, $resolution] = chainOfThree($this->deal);

    $candidates = KeyDateGraph::forDeal($this->deal)->anchorCandidatesFor($objection);

    expect(collect($candidates)->pluck('id')->all())->toBe([$acceptance->getKey()])
        ->and(collect($candidates)->pluck('id')->all())->not->toContain($resolution->getKey());
});

it('records one timeline entry for the whole cascade, not one per date', function (): void {
    [$acceptance] = chainOfThree($this->deal);

    ActivityEvent::query()->delete();

    $this->save->edit($acceptance, ['date' => '2026-09-04']);

    $types = ActivityEvent::query()->pluck('event_type')->sort()->values()->all();

    /*
     * Eleven rows on a deal timeline for one edit is eleven rows nobody reads.
     * The fact somebody wants six weeks later is *"moving this moved two
     * others"*, which is a single sentence.
     */
    expect($types)->toBe(['key_date.cascaded', 'key_date.moved']);
});

it('drops a recomputation that lands on the day the row already held', function (): void {
    $anchor = $this->save->add($this->deal, ['name' => 'Closing', 'date' => '2026-08-21']);

    // Two business days after a Friday is the following Tuesday.
    $derived = $this->save->add($this->deal, [
        'name' => 'Funds due',
        'anchor_key_date_id' => $anchor->getKey(),
        'offset_days' => 2,
        'offset_basis' => OffsetBasis::Business->value,
    ]);

    expect($derived->date->toDateString())->toBe('2026-08-25');

    /*
     * Friday → Saturday. Two business days from either is the same Tuesday, so
     * nothing downstream moves and the preview must not claim it did.
     */
    $result = $this->save->edit($anchor, ['date' => '2026-08-22']);

    expect($result->movedCount())->toBe(0)
        ->and($derived->refresh()->date->toDateString())->toBe('2026-08-25');
});

it('leaves a dependent standing when its anchor is deleted', function (): void {
    [$acceptance, $objection] = chainOfThree($this->deal);

    $this->save->remove($acceptance);

    /*
     * The obligation in the contract did not go away because somebody tidied
     * up the calendar. It keeps its day, stops following, and **says so** —
     * which is the half that was missing: nulling the anchor and the offset
     * left the row indistinguishable from a day somebody typed, and
     * `detached_at` was a column nothing could read.
     *
     * The offset stays because it is what the date has to say about itself,
     * and because a row still flagged derived is one the composite FK's
     * `ON DELETE SET NULL` cannot touch — the CHECK refuses it, and the
     * force-delete thirty days later would abort the whole team's purge.
     */
    $objection->refresh();

    expect($objection->exists)->toBeTrue()
        ->and($objection->date->toDateString())->toBe('2026-09-11')
        ->and($objection->is_derived)->toBeFalse()
        ->and($objection->wasDetached())->toBeTrue()
        ->and($objection->anchor_key_date_id)->toBe($acceptance->getKey())
        ->and($objection->offset_days)->toBe(10);
});

it('says a date used to follow one that has been deleted, without naming it', function (): void {
    /*
     * S18's three states — derived, detached, neither — have to survive the
     * anchor being gone, or the PRD's *"rather than presenting a typed date it
     * did not type"* is a sentence with nothing keeping it.
     *
     * The removed anchor is not named: it does not exist any more, and naming
     * it would send somebody looking for it. How far behind the date ran is
     * the part that still means something.
     */
    [$acceptance, $objection] = chainOfThree($this->deal);

    $this->save->remove($acceptance);

    $row = app(App\Queries\DealDates::class)->forDeal($this->deal->fresh());

    $detached = collect($row)->firstWhere('id', $objection->getKey());

    expect($detached['isDerived'])->toBeFalse()
        ->and($detached['wasDetached'])->toBeTrue()
        ->and($detached['anchor'])->toBeNull()
        ->and($detached['derivation'])->toContain('since been removed')
        ->and($detached['derivation'])->not->toContain($acceptance->name);
});

it('lets the purge force-delete an anchor without the CHECK refusing the detach', function (): void {
    /*
     * The other half of `remove()`'s "belt and braces", and it did not work.
     *
     * `ON DELETE SET NULL (anchor_key_date_id)` is an UPDATE, and Postgres
     * evaluates the CHECK on it — so a dependant still flagged `is_derived`
     * cannot be detached by the database: the force-delete raises 23514, and
     * `records:purge` deletes a whole table per statement inside one
     * transaction, so a single such row would stop that team's nightly purge
     * indefinitely.
     *
     * Clearing the flag at remove-time is what leaves the FK a row it is
     * allowed to touch. This is the thirty-days-later half, run here in one
     * step.
     */
    [$acceptance, $objection] = chainOfThree($this->deal);

    $this->save->remove($acceptance);

    KeyDate::withoutTeamScope()->withTrashed()->whereKey($acceptance->getKey())->forceDelete();

    $objection->refresh();

    expect($objection->exists)->toBeTrue()
        ->and($objection->date->toDateString())->toBe('2026-09-11')
        // The database nulled the pointer; the row still says it was detached,
        // which is why `wasDetached()` reads the column and not the anchor.
        ->and($objection->anchor_key_date_id)->toBeNull()
        ->and($objection->wasDetached())->toBeTrue();
});

it('does not count an extracted date nobody has confirmed', function (): void {
    $pending = KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $confirmed = KeyDate::factory()->confirmed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $manual = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    expect($pending->isPending())->toBeTrue()
        ->and($confirmed->isPending())->toBeFalse()
        ->and($manual->isPending())->toBeFalse()
        ->and($manual->source)->toBe(KeyDateSource::Manual);

    $counted = KeyDate::query()->confirmed()->pluck('id')->sort()->values()->all();

    expect($counted)->toBe(collect([$confirmed->getKey(), $manual->getKey()])->sort()->values()->all());
});

it('refuses an anchor this deal does not have, rather than saving a plain date', function (): void {
    /*
     * An anchor id that resolves to nothing used to fall through to the
     * typed-date branch: a *derived* payload saved as a plain date, with no
     * anchor, no offset, and nothing to say the request had not been honoured.
     *
     * `SaveKeyDateRequest` refuses it first for every HTTP caller, so the
     * callers who can reach this are the ones not written yet — F5.3's
     * automation, an importer, Slice 5's extraction — which is exactly when a
     * silent fall-through costs the most, because nobody is watching a screen.
     */
    $anchor = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    $elsewhere = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => Deal::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
        'name' => 'Someone else’s closing',
        'date' => '2026-10-01',
    ]);

    expect(fn () => app(SaveKeyDate::class)->add($this->deal, [
        'name' => 'Inspection objection',
        'anchor_key_date_id' => $elsewhere->getKey(),
        'offset_days' => 10,
        'offset_basis' => 'calendar',
        'date' => '2026-09-11',
    ]))->toThrow(UnknownAnchor::class);

    expect(KeyDate::query()->where('deal_id', $this->deal->getKey())->count())->toBe(1);

    // The control: the same payload against an anchor that is on this deal.
    $derived = app(SaveKeyDate::class)->add($this->deal, [
        'name' => 'Inspection objection',
        'anchor_key_date_id' => $anchor->getKey(),
        'offset_days' => 10,
        'offset_basis' => 'calendar',
    ]);

    expect($derived->follows())->toBeTrue()
        ->and($derived->date->toDateString())->toBe('2026-09-11');
});

it('uses the critical reminder schedule for a critical date and the default otherwise', function (): void {
    $ordinary = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $critical = KeyDate::factory()->critical()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    expect($ordinary->reminderDays())->toBe(KeyDate::DEFAULT_REMINDERS)
        ->and($critical->reminderDays())->toBe(KeyDate::CRITICAL_REMINDERS);

    /*
     * Null and `[]` are different answers, which is why the column is nullable
     * rather than defaulted: an empty list is somebody having deliberately
     * turned every reminder off, and a default in the column would make that
     * unreachable.
     */
    $silent = $this->save->edit($critical, ['reminder_offsets' => []]);

    expect($silent->keyDate->reminderDays())->toBe([]);
});
