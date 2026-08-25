<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The tenant boundary (PRD §4.1 F1.1, §6.2 · IA §2).
 *
 * The one business model that is not team-scoped, because it is the thing
 * everything else is scoped *to*. IA §11: **Team**, never Organization,
 * Account, Workspace, or Company.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $brand_accent_color
 * @property string|null $sending_identity_name
 * @property string|null $sending_identity_email
 * @property string|null $signature_block
 * @property string $timezone
 * @property Carbon|null $sends_disabled_at
 * @property string|null $sends_disabled_reason
 * @property bool $sandbox_mode
 * @property int $hourly_send_limit
 * @property int $daily_send_limit
 * @property Carbon|null $approval_required_until
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $suspended_at
 * @property Carbon|null $purge_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'name',
    'slug',
    'logo_path',
    'brand_accent_color',
    'sending_identity_name',
    'sending_identity_email',
    'signature_block',
    'timezone',
    'settings',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sandbox_mode' => 'boolean',
            'sends_disabled_at' => 'datetime',
            'approval_required_until' => 'datetime',
            'suspended_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * F5.9's hard switch (PRD §4.5 · issue #96).
     *
     * Read off its own column rather than out of `settings`, which is where
     * Slice 1 parked it *"so nothing has to guess at the key later"*. This is
     * later: a safety control in a JSON blob cannot answer *"which teams are
     * halted right now"*, which is the question somebody asks during the
     * incident this switch exists for. The migration carried the old value
     * across.
     */
    public function sendsAreDisabled(): bool
    {
        return $this->sends_disabled_at !== null;
    }

    /**
     * F5.7's *"default to approval for a team's first 30 days"*, as a fact
     * rather than as advice in the documentation.
     *
     * While this holds, every message waits for a human whatever its
     * automation says — the period when a team's templates are least tested
     * is the period their clients are most exposed to them.
     */
    public function approvalIsMandatory(?Carbon $at = null): bool
    {
        return $this->approval_required_until !== null
            && $this->approval_required_until->isAfter($at ?? Carbon::now());
    }
}
