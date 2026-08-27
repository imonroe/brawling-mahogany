<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuppressionReason;
use Database\Factories\SuppressedAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An address this product will not write to again, for anybody (#95).
 *
 * ## The sanctioned cross-tenant record
 *
 * There is no `team_id` here and no {@see Concerns\BelongsToTeam}.
 * That is a decision, recorded in `TEAM_AGNOSTIC_MODELS` and argued in the
 * migration and in ADR 0002: a dead mailbox is dead for every team, and SES
 * measures bounce and complaint rates across the **whole account** (PRD
 * §12.2), so one team writing repeatedly to a bad address is spending every
 * other team's deliverability.
 *
 * Issue #95 is explicit that this must be *"built explicitly rather than
 * falling out of a scope gap"* — the difference being that a scope gap is
 * something nobody decided, and this is something somebody has to defend.
 *
 * ## And the reason nothing team-facing reads it
 *
 * A row here says an address is dead, or that whoever holds it reported
 * somebody as a spammer. Two teams sharing a client would learn about each
 * other's correspondence from it. So the read that matters —
 * {@see self::suppresses()} — answers a **yes/no** and nothing else, and only
 * the platform console (which is already cross-tenant by design) ever sees
 * `discovered_by_team_id`. A team is told *"we are not writing to this
 * address"* and why, in words about the address, never about another team.
 *
 * @property string $id
 * @property string $email
 * @property SuppressionReason $reason
 * @property string|null $detail
 * @property string|null $discovered_by_team_id
 * @property Carbon $suppressed_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class SuppressedAddress extends Model
{
    /** @use HasFactory<SuppressedAddressFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'suppressed_at' => 'datetime',
        ];
    }

    /**
     * The one form an address is ever stored or compared in.
     *
     * Both the write and the read go through this, which is the only thing
     * that makes the unique index mean what it says. A suppression stored as
     * `Emily@Bosart.test` and looked up as `emily@bosart.test` is a
     * suppression that silently does not apply — and it would fail open,
     * sending to an address the account has already been told is bad.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Is this address suppressed?
     *
     * The whole of the team-facing API, deliberately. Callers get a boolean
     * and, where they need to explain themselves, a {@see SuppressionReason} —
     * never the row, never the team that discovered it, never the date another
     * team's client reported somebody.
     */
    public static function suppresses(string $email): ?SuppressionReason
    {
        return self::query()
            ->where('email', self::normalise($email))
            ->value('reason');
    }

    /**
     * Which of these addresses are suppressed, in one query.
     *
     * A send addressed to four people would otherwise ask four times, on the
     * request that is already holding a database transaction open.
     *
     * @param  list<string>  $emails
     * @return array<string, SuppressionReason>
     */
    public static function among(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return self::query()
            ->whereIn('email', array_map(self::normalise(...), $emails))
            ->pluck('reason', 'email')
            ->all();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDiscoveredBy(Builder $query, string $teamId): Builder
    {
        return $query->where('discovered_by_team_id', $teamId);
    }
}
