<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Team;
use App\Support\Tenancy\MissingTeamContextException;
use App\Support\Tenancy\TeamContext;
use Closure;

/**
 * A queued job carries its team explicitly (ADR 0002).
 *
 * This is the part issue #41 calls *"easy to forget and expensive to get
 * wrong"*: a job has no session, and one that infers a team from "the last one
 * seen" is a leak waiting for a busy queue worker. So the id is captured at
 * dispatch, re-established on handle, and a job that reaches `handle()`
 * without one throws rather than running unscoped.
 *
 * @phpstan-require-implements \Illuminate\Contracts\Queue\ShouldQueue
 */
trait RunsForTeam
{
    public string $teamId;

    public function forTeam(Team|string $team): static
    {
        $this->teamId = $team instanceof Team ? $team->getKey() : $team;

        return $this;
    }

    /**
     * Run the body of the job inside its own team's context.
     *
     * @template TReturn
     *
     * @param  Closure(Team): TReturn  $callback
     * @return TReturn
     */
    protected function withinTeam(Closure $callback): mixed
    {
        if (! isset($this->teamId) || $this->teamId === '') {
            throw MissingTeamContextException::for(static::class);
        }

        // Unscoped, because resolving the job's own team is the one lookup
        // that cannot already be inside the scope it is about to establish.
        $team = Team::query()->find($this->teamId);

        if (! $team instanceof Team) {
            throw MissingTeamContextException::for(static::class);
        }

        return app(TeamContext::class)->runFor($team, fn () => $callback($team));
    }
}
