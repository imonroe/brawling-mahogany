<?php

declare(strict_types=1);

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
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/dashboard')->assertOk();

    // Slice 1 added the tenancy layer to every request: resolving the team,
    // the person's memberships for the switcher, and the permissions the
    // navigation hides itself by. Each is one query and each is on every
    // page, which is exactly why they are counted.
    expect($queries)->toBeLessThanOrEqual(14);
});
