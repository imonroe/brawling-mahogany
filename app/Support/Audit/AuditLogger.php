<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditEntry;
use App\Models\Person;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The one way anything writes to the append-only audit log.
 *
 * PRD §9 lists what it must cover: authentication, permission changes, gate
 * overrides, document access, extraction reviews, and super-admin
 * impersonation. Routing all of it through one class is what makes the
 * redaction (PRD §9: no PII in logs, ever) unskippable rather than something
 * each caller remembers.
 */
final class AuditLogger
{
    public function __construct(
        private readonly AuditRedactor $redactor,
        private readonly TeamContext $teams,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?string $teamId = null,
        ?string $actorPersonId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditEntry {
        return AuditEntry::query()->create([
            'team_id' => $teamId ?? $this->teams->id(),
            'actor_person_id' => $actorPersonId ?? $this->currentActorId(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass() ?? $auditableType,
            'auditable_id' => $auditable?->getKey() ?? $auditableId,
            'before' => $this->redactor->redact($before),
            'after' => $this->redactor->redact($after),
            'reason' => $reason,
            'ip' => $this->currentIp(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record a change to a model, taking the payloads from the model itself.
     *
     * `getChanges()` after a save is exactly the set of attributes that moved,
     * which keeps the entry to what actually happened rather than the whole
     * row.
     */
    public function recordChange(string $action, Model $model, ?string $reason = null): AuditEntry
    {
        $changes = $model->getChanges();
        $before = array_intersect_key($model->getOriginal(), $changes);

        return $this->record(
            action: $action,
            auditable: $model,
            reason: $reason,
            before: $before,
            after: $changes,
        );
    }

    private function currentActorId(): ?string
    {
        $person = auth()->user();

        return $person instanceof Person ? $person->getKey() : null;
    }

    private function currentIp(): ?string
    {
        if (! app()->bound(Request::class)) {
            return null;
        }

        return app(Request::class)->ip();
    }
}
