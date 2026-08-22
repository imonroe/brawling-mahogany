<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One row in the append-only security log (PRD §6.2, §9 Audit).
 *
 * Not `ActivityEvent`, and never merged with it. PRD §7.7 keeps them apart on
 * purpose: `activity_events` is a product feature that users read, and this is
 * a security record that has to survive somebody trying to tidy up. Different
 * purpose, different audience, different retention.
 *
 * Deliberately no `HasProductDefaults`: no soft deletes, no `updated_at`, and
 * no model path that permits either. The database refuses too — the migration
 * installs triggers — so this class is the polite failure and the trigger is
 * the real one.
 *
 * Deliberately no `BelongsToTeam` either. The audit log outlives the team it
 * describes (issue #57: *"the audit trail of the purge survives it"*), and
 * some entries have no team at all — a failed sign-in against an address that
 * belongs to nobody. Reading it is gated by policy instead, and the isolation
 * suite records the exception.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string|null $actor_person_id
 * @property string $action
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property string|null $ip
 * @property Carbon $created_at
 */
#[Fillable([
    'team_id',
    'actor_person_id',
    'action',
    'auditable_type',
    'auditable_id',
    'before',
    'after',
    'reason',
    'ip',
    'created_at',
])]
class AuditEntry extends Model
{
    use HasUlids;

    protected $table = 'audit_log';

    /** There is no `updated_at`: an audit row is never updated. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('audit_log is append-only: an entry cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('audit_log is append-only: an entry cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'actor_person_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
