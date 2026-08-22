<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Team;
use Closure;

/**
 * The resolved team for the current request, job, or command.
 *
 * ADR 0002 is explicit that there is no *ambient* team: a queued job, a
 * scheduled command, and a webhook each have to establish one, and code that
 * touches team-scoped data without one throws rather than guessing. This class
 * is the single place that answer lives, so there is exactly one thing to
 * bind, one thing to read, and one thing to assert about in a test.
 */
final class TeamContext
{
    private ?Team $team = null;

    /**
     * The audited bypass (ADR 0002, "How the super administrator bypasses it").
     *
     * Deliberately not a public flag anybody can flip: it is set only by
     * `runWithoutScope()`, which is called only by the super admin console and
     * the cross-team console commands.
     */
    private bool $unscoped = false;

    public function set(?Team $team): void
    {
        $this->team = $team;
    }

    public function get(): ?Team
    {
        return $this->team;
    }

    public function id(): ?string
    {
        return $this->team?->getKey();
    }

    public function has(): bool
    {
        return $this->team instanceof Team;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /**
     * The team every scoped query must be constrained to.
     *
     * @throws MissingTeamContextException when nothing has established one
     */
    public function requireId(string $context): string
    {
        $id = $this->id();

        if ($id === null) {
            throw MissingTeamContextException::for($context);
        }

        return $id;
    }

    /**
     * Run a callback with the global scope lifted.
     *
     * The two sanctioned callers are the super admin console and the console
     * commands that operate across teams. Both audit the access separately —
     * this class does not write the audit entry itself, because the entry has
     * to name *what* was looked at, which only the caller knows.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutScope(Closure $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    /**
     * Run a callback inside one team's context, restoring the previous one.
     *
     * This is how a job re-enters the team it was dispatched from, and how the
     * scheduler iterates teams explicitly rather than running unscoped.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(?Team $team, Closure $callback): mixed
    {
        $previous = $this->team;
        $previouslyUnscoped = $this->unscoped;

        $this->team = $team;
        $this->unscoped = false;

        try {
            return $callback();
        } finally {
            $this->team = $previous;
            $this->unscoped = $previouslyUnscoped;
        }
    }
}
