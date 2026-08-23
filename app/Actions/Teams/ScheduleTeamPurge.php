<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Team;
use App\Support\Audit\AuditLogger;

/**
 * Schedule and cancel a tenant purge (PRD §9 Deletion · issue #57).
 *
 * *"Team deletion purges within 30 days"* — scheduled rather than immediate,
 * and cancellable during the window. A customer who leaves in anger on Friday
 * and changes their mind on Monday should still have their deals.
 */
final class ScheduleTeamPurge
{
    public const WINDOW_DAYS = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    public function schedule(Team $team): void
    {
        $team->forceFill([
            'purge_after' => now()->addDays(self::WINDOW_DAYS),
            'suspended_at' => $team->suspended_at ?? now(),
        ])->save();

        $this->audit->record(
            action: 'team.purge_scheduled',
            auditable: $team,
            teamId: $team->getKey(),
            after: ['purge_after' => $team->purge_after?->toIso8601String()],
        );
    }

    public function cancel(Team $team): void
    {
        $team->forceFill(['purge_after' => null])->save();

        $this->audit->record(
            action: 'team.purge_cancelled',
            auditable: $team,
            teamId: $team->getKey(),
        );
    }
}
