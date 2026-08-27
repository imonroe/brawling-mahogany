<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One browser that has agreed to be pushed to (#103 · F12.2).
 *
 * Deliberately **not** team-scoped, for the reason `Passkey` is not: a
 * credential belongs to a human, not to a tenancy, and a person who works for
 * two agencies has one phone. The migration argues it at length and
 * `ModelTenancyConventionTest` records the exception.
 *
 * @property string $id
 * @property string $person_id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $user_agent
 * @property Carbon|null $last_seen_at
 */
#[Fillable([])]
class PushSubscription extends Model
{
    /**
     * @use HasFactory<PushSubscriptionFactory>
     *
     * `HasUlids` directly rather than through `HasProductDefaults`, which is
     * how every other model in this product gets it: that trait also brings
     * `SoftDeletes`, and this table deliberately has none — a dead endpoint
     * is not recoverable state. See the migration.
     */
    use HasFactory, HasUlids;

    /**
     * How long a subscription nothing has reached may sit before it is swept.
     *
     * Generous on purpose. A push service is not obliged to tell us a device
     * has been wiped and many never do, so this is the only thing that
     * eventually clears those — but somebody who has not been sent anything
     * for a fortnight (a quiet spell, a holiday) has not stopped existing, and
     * silently unsubscribing them would present as *"push just stopped
     * working"* with nothing to point at.
     */
    public const STALE_AFTER_DAYS = 180;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPerson(Builder $query, Person $person): Builder
    {
        return $query->where('person_id', $person->getKey());
    }

    /**
     * A short, human name for the device.
     *
     * S55 lists a person's devices so they can switch one off, and *"three
     * subscriptions"* is not something anybody can act on. The user-agent
     * string is unreadable, so this reduces it to the part somebody would
     * recognise — and falls back to something honest rather than to the raw
     * string, which is long enough to break the row it sits in.
     */
    public function deviceName(): string
    {
        $agent = $this->user_agent ?? '';

        foreach ([
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android phone',
            'Macintosh' => 'Mac',
            'Windows' => 'Windows PC',
            'Linux' => 'Linux computer',
        ] as $needle => $label) {
            if (Str::contains($agent, $needle)) {
                return $label;
            }
        }

        return 'This device';
    }
}
