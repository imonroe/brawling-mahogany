<?php

declare(strict_types=1);

use App\Enums\ExtractionState;
use App\Jobs\RunDocumentExtraction;
use App\Models\Extraction;
use App\Models\Team;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Queue;

/**
 * The document a stopped read holds hostage (#115).
 *
 * `extractions_one_running` is a partial unique index over
 * `(team_id, document_id)` for the two running states, and `StartExtraction`
 * asks the same question before it writes. Both are right, and together they
 * mean a row that stops moving takes its document out of the product: no
 * second attempt, ever, on that file.
 *
 * `RunDocumentExtraction::failed()` covers a job that gives up. It cannot cover
 * a worker that is *killed* — an OOM, a `queue:restart`, a container eviction —
 * because a killed process runs no shutdown handler. That is the case here, and
 * it is the ordinary one on a small droplet.
 *
 * ## The clock moves; it is not pinned
 *
 * docs/Testing.md: *"for anything that partitions time — a sweep with a
 * high-water mark, a rolling window — a frozen clock is the one arrangement
 * production never produces."* Every row below is written at a named instant
 * and swept at a different one, and the case that matters most is the row
 * written on the *near* side of the threshold.
 */
beforeEach(function (): void {
    Queue::fake();

    [$this->team] = $this->teamWithMember();
    [$this->otherTeam] = $this->teamWithMember();
});

/** A row in a given state, in a given team, at whatever the clock says now. */
function strandedExtraction(
    Team $team,
    ExtractionState $state,
): Extraction {
    return app(TeamContext::class)->runFor($team, fn (): Extraction => Extraction::factory()
        ->state(fn (): array => [
            'state' => $state,
            'started_at' => $state === ExtractionState::Queued ? null : now(),
            'completed_at' => null,
        ])
        ->create(['team_id' => $team->getKey()]));
}

it('fails a claimed read that stopped, so the document can be extracted again', function (): void {
    $this->travelTo('2026-09-10 09:00:00');

    $stranded = strandedExtraction($this->team, ExtractionState::Processing);

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    $stranded->refresh();

    expect($stranded->state)->toBe(ExtractionState::Failed)
        ->and($stranded->state->isFinal())->toBeTrue()
        ->and($stranded->error_code)->toBe('extraction_stranded')
        ->and($stranded->completed_at)->not->toBeNull();

    /*
     * And the point of all of it: the index no longer matches this row, so the
     * document is free. Asserted as the *question `StartExtraction` asks*
     * rather than by calling it, because calling it needs a provider.
     */
    $running = app(TeamContext::class)->runFor($this->team, fn (): bool => Extraction::query()
        ->where('document_id', $stranded->document_id)
        ->whereIn('state', [ExtractionState::Queued->value, ExtractionState::Processing->value])
        ->exists());

    expect($running)->toBeFalse();
});

it('never calls the provider again for a claimed row', function (): void {
    /*
     * `automations:reap-unconfirmed`'s rule, and it is the reason a `processing`
     * row is failed rather than re-queued: the claim was taken, so the call may
     * have been made and the money may already be spent. Nobody knows, so a
     * person is told — a second call would be guessing in the expensive
     * direction.
     */
    $this->travelTo('2026-09-10 09:00:00');

    strandedExtraction($this->team, ExtractionState::Processing);

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    Queue::assertNotPushed(RunDocumentExtraction::class);
});

it('re-queues a row that was never claimed, because nothing was spent on it', function (): void {
    /*
     * The other window, and it is `automations:dispatch-due`'s: a web process
     * that died between the commit and the dispatch leaves a perfectly good row
     * with no job coming for it. Nothing was claimed and nothing was charged,
     * so the honest repair is to run it rather than to tell somebody it failed.
     */
    $this->travelTo('2026-09-10 09:00:00');

    $waiting = strandedExtraction($this->team, ExtractionState::Queued);

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    expect($waiting->fresh()?->state)->toBe(ExtractionState::Queued);

    Queue::assertPushed(
        RunDocumentExtraction::class,
        fn (RunDocumentExtraction $job): bool => $job->extractionId === $waiting->getKey(),
    );
});

it('leaves a read that is still young alone', function (): void {
    /*
     * The control, and the one that decides whether this command is safe to
     * schedule at all. `ReapUnconfirmedSends` argues it: the age of a claim
     * cannot tell a dead worker from a live one, so the only defence is
     * distance. A sweep that failed a read three minutes in would be charging
     * for a second call on every scanned contract.
     */
    $this->travelTo('2026-09-10 14:00:00');

    $young = strandedExtraction($this->team, ExtractionState::Processing);

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    expect($young->fresh()?->state)->toBe(ExtractionState::Processing);

    Queue::assertNotPushed(RunDocumentExtraction::class);
});

it('leaves a read that finished alone, however long ago it finished', function (): void {
    /*
     * A `complete` row is months old by design and must never be touched — this
     * is the assertion that separates *"stopped moving"* from *"is not
     * recent"*, and a predicate on `updated_at` alone would fail it.
     */
    $this->travelTo('2026-05-01 09:00:00');

    $done = app(TeamContext::class)->runFor($this->team, fn (): Extraction => Extraction::factory()
        ->complete()
        ->create(['team_id' => $this->team->getKey()]));

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    expect($done->fresh()?->state)->toBe(ExtractionState::Complete);
});

it('sweeps every team, because a scheduled run belongs to none of them', function (): void {
    /*
     * The sweep shape ADR 0002 sanctions, asserted rather than assumed: a
     * command that quietly resolved a tenant would release one team's documents
     * and leave every other team's held.
     */
    $this->travelTo('2026-09-10 09:00:00');

    $mine = strandedExtraction($this->team, ExtractionState::Processing);
    $theirs = strandedExtraction($this->otherTeam, ExtractionState::Processing);

    $this->travelTo('2026-09-10 15:00:00');

    $this->artisan('extractions:reap-stranded')->assertSuccessful();

    expect($mine->fresh()?->state)->toBe(ExtractionState::Failed)
        ->and($theirs->fresh()?->state)->toBe(ExtractionState::Failed);
});
