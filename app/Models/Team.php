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
    /*
     * `logo_path` is deliberately **absent**: it is a key on a private disk,
     * and `TeamLogo` is the only thing that writes it. The same reasoning as
     * ADR 0002's rule about `team_id` — a request body must not choose where
     * bytes are read from.
     */
    'brand_accent_color',
    'sending_identity_name',
    'sending_identity_email',
    'signature_block',
    'timezone',
    'settings',
])]
class Team extends Model
{
    /**
     * How long F5.7's mandatory review window runs for a new team.
     *
     * Named here rather than inlined, because two places have to agree about
     * it: the `creating` hook below and the settings screen that lets a team
     * start a fresh window after ending one.
     */
    public const APPROVAL_WINDOW_DAYS = 30;

    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * The window opens when the team does (PRD §4.5 F5.7 · #96).
     *
     * On the model rather than in `ProvisionTeam`, and that is the whole
     * point. The migration promised *"set on team creation"* and nothing kept
     * the promise — `ProvisionTeam` writes four columns and this was not one
     * of them — so F5.7's *"default to approval for a team's first 30 days"*
     * was live for every team that already existed and dead for every team it
     * was written for. Exactly backwards, and invisible: a new team's messages
     * simply went.
     *
     * `ProvisionTeam` is not the only door. `/admin` provisions teams, the
     * factory builds them, and a later slice will have a signup flow. This
     * codebase's recurring finding is that a rule written into one caller is a
     * rule the next caller is written without, so the rule goes where every
     * caller passes.
     *
     * Not overwritten when a value is already present: a seeder or a test that
     * says *"this team is past its first month"* is making a statement, and a
     * hook that argued with it would make the state untestable.
     */
    protected static function booted(): void
    {
        static::creating(function (self $team): void {
            /*
             * `array_key_exists` and not a null check, which is the whole of
             * it: an explicit `null` is a caller **saying** this team has no
             * window, and a hook that could not tell that from "nothing was
             * passed" would make the state untestable.
             */
            if (! array_key_exists('approval_required_until', $team->getAttributes())) {
                $team->approval_required_until = Carbon::now()->addDays(self::APPROVAL_WINDOW_DAYS);
            }
        });
    }

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
