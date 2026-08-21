<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PRD §9: the dashboard renders under 400ms server-side at p95, with 25 active
 * deals and 500 past clients. That budget cannot be measured until Slice 2
 * puts deals behind it — what can be held from today is the query count,
 * because an N+1 introduced early is the usual way that budget is lost.
 *
 * The number is deliberately tight. Raise it when a feature genuinely needs
 * the queries, and say why in the commit.
 */

it('renders the dashboard within its query budget', function (): void {
    $this->actingAs(User::factory()->create());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/dashboard')->assertOk();

    expect($queries)->toBeLessThanOrEqual(10);
});
