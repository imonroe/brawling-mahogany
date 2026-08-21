<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suites
|--------------------------------------------------------------------------
|
| Four layers, each with a job (docs/Testing.md):
|
|   Unit         pure logic — gate evaluators, date offsets, merge fields
|   Feature      HTTP routes through policies and Inertia responses
|   Isolation    cross-tenant access is refused. A release blocker, PRD §8.2
|   Performance  query-count and latency budgets on the screens that carry load
|
| Everything but Unit talks to a real Postgres, one transaction per test.
|
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Isolation', 'Performance');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeSnakeCase', function () {
    // IA §8: state and enum values are snake_case in code, always.
    expect($this->value)->toMatch('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/');

    return $this;
});
