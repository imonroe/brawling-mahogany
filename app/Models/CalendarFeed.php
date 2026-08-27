<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\CalendarFeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One subscribable calendar (PRD §4.8 F8.3 · S60 · issue #108).
 *
 * Personal or per-deal — see the migration for why that is one column rather
 * than two tables — and hashed **and** encrypted, for two different reasons
 * the migration also states.
 *
 * @property string $id
 * @property string $team_id
 * @property string $person_id
 * @property string|null $deal_id
 * @property string $token_hash
 * @property string $token
 * @property string $name
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_fetched_at
 * @property int $fetch_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name'])]
class CalendarFeed extends Model
{
    /** @use HasFactory<CalendarFeedFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * How far a feed looks, in each direction.
     *
     * A calendar client wants enough history to place what it is showing and
     * enough future to be worth subscribing to — and an unbounded feed on a
     * three-year-old team is a document that grows forever and is re-fetched
     * every few hours. Ninety days back and a year ahead covers the life of a
     * transaction twice over.
     */
    public const DAYS_BACK = 90;

    public const DAYS_AHEAD = 365;

    /** 32 bytes, base62 — the same shape a status page link uses. */
    public const TOKEN_LENGTH = 43;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /*
             * Laravel's application-level encryption, which PRD §9 asks for by
             * name for *"stored credentials and tokens"*. Reversible on
             * purpose: S60 shows the URL again, and a hash cannot.
             */
            'token' => 'encrypted',
            'revoked_at' => 'datetime',
            'last_fetched_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isPersonal(): bool
    {
        return $this->deal_id === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * The URL a person pastes into their calendar.
     *
     * `webcal://` is deliberately **not** used, tempting as it is: Apple
     * Calendar understands it, Google's *"from URL"* box does not, and a
     * scheme half the audience has to edit out is worse than one nobody has
     * to. Both clients accept `https` and subscribe correctly.
     */
    public function url(): string
    {
        return url('/calendar/feeds/'.$this->token.'.ics');
    }
}
