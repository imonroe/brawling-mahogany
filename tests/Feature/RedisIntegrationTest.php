<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Production runs cache, queue, and sessions on Redis (.env.example), so the
 * suite has to touch Redis somewhere or the pinned CI service proves nothing.
 *
 * The rest of the suite deliberately uses the array driver and the sync queue
 * — those are faster and make assertions simpler. This is the one place the
 * real driver is exercised, and it is the place a Redis version bump or a
 * misconfigured connection will fail first.
 *
 * `phpunit.xml` pins REDIS_DB and REDIS_CACHE_DB to 15, so nothing here can
 * reach the database a running local stack is using.
 */
beforeEach(function (): void {
    // A connection failure here is a real failure, not a reason to skip: CI
    // runs the same pinned Redis the compose stack does.
    Redis::connection()->ping();
});

afterEach(function (): void {
    // Forget the probes rather than flushing: RedisStore::flush() is an
    // unconditional FLUSHDB that ignores the prefix, and phpunit.xml pins the
    // database precisely so a stray flush cannot reach a developer's stack.
    foreach (['slice-0:probe'] as $key) {
        Cache::store('redis')->forget($key);
    }
});

it('reads and writes through the Redis cache store', function (): void {
    Cache::store('redis')->put('slice-0:probe', 'value', 60);

    expect(Cache::store('redis')->get('slice-0:probe'))->toBe('value');

    Cache::store('redis')->forget('slice-0:probe');

    expect(Cache::store('redis')->get('slice-0:probe'))->toBeNull();
});

it('honours a cache lock, which the advancement service will depend on', function (): void {
    // AdvanceWorkflow (Slice 2) is the single mutation path for workflow
    // state, and it needs a lock that actually locks.
    $lock = Cache::store('redis')->lock('slice-0:advance', 5);

    expect($lock->get())->toBeTrue()
        ->and(Cache::store('redis')->lock('slice-0:advance', 5)->get())->toBeFalse();

    $lock->release();
});

it('pushes to a real Redis queue', function (): void {
    $queue = Queue::connection('redis');
    $name = 'slice-0-probe';

    // A probe queue name of its own: the local Horizon watches `default`.
    $queue->push('App\\Jobs\\Probe', ['ok' => true], $name);

    expect($queue->size($name))->toBe(1);

    $queue->pop($name)?->delete();

    expect($queue->size($name))->toBe(0);
});
