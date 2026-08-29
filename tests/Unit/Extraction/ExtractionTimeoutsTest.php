<?php

declare(strict_types=1);

use App\Jobs\RunDocumentExtraction;

/**
 * Four numbers in three files, and they only work in one order (#115).
 *
 * Review round 2 found them out of order, and the shape of the defect is why
 * this file exists rather than a comment: nobody edits all four together.
 * `config/extraction.php` sets the provider's patience, `config/horizon.php`
 * sets the worker's, `config/queue.php` sets Redis's, and the job sets its own
 * — four reasons to change one number, and no reason to look at the other
 * three.
 *
 * The order that has to hold, tightest first:
 *
 *     provider timeout  <  job timeout  <  retry_after
 *
 * Each inequality is a different failure when it inverts:
 *
 * - **Job at or below the provider's.** The worker kills the read before the
 *   provider gives up, so a slow document can never succeed however patient
 *   the provider config claims to be — and the call is already paid for. This
 *   is the one that shipped: Horizon's supervisor timeout is 60 and the
 *   provider's is 180, with the job naming neither.
 * - **`retry_after` at or below the job's.** Redis makes the message visible
 *   again while the worker still holds it, and a second worker starts the same
 *   extraction. `PerformExtraction::claim()` stops that becoming a second
 *   charge, which is the layer doing its job rather than the setting being
 *   right.
 *
 * Nothing here asserts a *literal* except the relationship, so the numbers stay
 * free to move — which is the only way a guard like this survives contact with
 * somebody who genuinely needs a longer timeout.
 */
it('gives the job longer than the provider call it wraps', function (): void {
    $job = new RunDocumentExtraction('extraction-1');

    expect($job->timeout)->toBeGreaterThan((int) config('extraction.anthropic.timeout'));
});

it('keeps the queue’s visibility window wider than the job', function (): void {
    $job = new RunDocumentExtraction('extraction-1');

    expect((int) config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan($job->timeout);
});

it('overrides the worker’s own timeout rather than inheriting it', function (): void {
    /*
     * The assertion that makes the first one mean something. `Worker::
     * timeoutForJob()` prefers a job's own value over the worker's, so the job
     * declaring one is what actually applies — and if Horizon's supervisor
     * timeout were already the larger number this whole file would be
     * describing a problem that did not exist.
     *
     * Read from the `defaults` supervisor, which is what every environment in
     * `config/horizon.php` inherits and none of them overrides.
     */
    $job = new RunDocumentExtraction('extraction-1');

    $worker = (int) config('horizon.defaults.supervisor-1.timeout');

    expect($worker)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeGreaterThan($worker);
});

it('derives the job timeout, so the two cannot drift apart', function (): void {
    /*
     * A literal `public int $timeout = 240` would pass every case above and
     * silently stop being right the moment `EXTRACTION_TIMEOUT` is raised in a
     * `.env` — which is the ordinary way that value changes, and one no test
     * run can observe. So the derivation itself is asserted: move the provider
     * timeout and the job's must move with it.
     */
    $before = (new RunDocumentExtraction('extraction-1'))->timeout;

    config(['extraction.anthropic.timeout' => (int) config('extraction.anthropic.timeout') + 600]);

    expect((new RunDocumentExtraction('extraction-1'))->timeout)->toBe($before + 600);
});
