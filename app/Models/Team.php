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
     * The safety rails live in `settings` (PRD §4.5 F5.9).
     *
     * They are read from Slice 3 onward; the accessor exists now so nothing
     * has to guess at the key later.
     */
    public function sendsAreDisabled(): bool
    {
        return (bool) ($this->settings['no_sends'] ?? false);
    }
}
