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

it('derives the queue’s window too, so the ordering survives a raised timeout', function (): void {
    /*
     * Round 3's finding, and the reason it matters more than it looks:
     * `retry_after` was a **literal** 300 while the job's timeout derived, so
     * the three numbers were only in order at the shipped default. Any
     * `EXTRACTION_TIMEOUT` of 240 or more — well inside the range
     * `.env.example` calls reasonable for *"several pages of contract through
     * a vision model"* — inverted the very inequality this file exists to
     * hold, and did it in a `.env` on the droplet, which is the one place no
     * test run and no review can observe (#196).
     *
     * `config/queue.php` is read at boot, so the derivation is re-evaluated
     * here from the same env value rather than by re-reading the config the
     * test process already resolved.
     */
    $provider = (int) config('extraction.anthropic.timeout');

    /*
     * **`>=`, not `==`, and the difference is a configuration this repository
     * documents.** `.env.example` tells the reader they may set
     * `REDIS_QUEUE_RETRY_AFTER` *"only to widen the margin"* — so an equality
     * here is red for anybody who takes that advice. Worse, `compose.yaml`'s
     * `env_file: .env` puts a developer's whole `.env` into the environment
     * `make check` runs in, so it would be green in CI and red on one machine:
     * exactly the trap CLAUDE.md records from `ProductNameSeparationTest`.
     *
     * `>=` is still a real claim rather than a weakened one, because the
     * floor it compares against is **computed from the provider timeout**: a
     * literal `300` in `config/queue.php` fails this the moment
     * `EXTRACTION_TIMEOUT` goes past 180, which is the defect this file was
     * written for. What it no longer does is refuse an operator who widened
     * the margin deliberately.
     *
     * There is deliberately no case that raises the env var and re-reads:
     * `config/queue.php` is evaluated once at boot, so such a case would be
     * arithmetic on its own local variables — a test that passes with the
     * derivation deleted.
     */
    expect((int) config('queue.connections.redis.retry_after'))
        ->toBeGreaterThanOrEqual($provider + 120)
        ->and((new RunDocumentExtraction('extraction-1'))->timeout)->toBe($provider + 60);
});

it('holds the unique lock past the point a live sibling could still have it', function (): void {
    /*
     * `ShouldBeUnique` with no `uniqueFor` holds the lock until the job
     * completes or fails — right until a worker is **killed**, which runs no
     * shutdown handler and releases nothing. The id can then never be
     * dispatched again.
     *
     * That was survivable while every dispatch came from `StartExtraction`,
     * which mints a new row and so a new id. `extractions:reap-stranded` is the
     * first caller that re-dispatches an id already dispatched once, so a
     * leaked lock makes its repair a silent no-op that reports *"re-queued 1"*
     * every hour for ever.
     *
     * The window has to be past both numbers, not either: past the job's own
     * timeout, and past the queue's visibility window, since a message made
     * visible again is a sibling that may still be starting.
     */
    $job = new RunDocumentExtraction('extraction-1');

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueFor)->toBeGreaterThan((int) config('queue.connections.redis.retry_after'));
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
