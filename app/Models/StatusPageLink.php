<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\StatusPageLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One client's access to one deal's status page (PRD §4.7 F7.1 · issue #110).
 *
 * Two credentials on one row, with two lifetimes and one revoke — see the
 * migration for why that is one grant rather than two tables.
 *
 * ## The plaintext lives for one function call
 *
 * `IssueStatusPageLink` returns it and this model never holds it, the way
 * `TeamInvitation` does. A leaked dump must not be a set of working keys to
 * every client's transaction.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string $team_membership_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $session_token_hash
 * @property Carbon|null $session_expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property Carbon|null $last_seen_at
 * @property int $view_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([])]
class StatusPageLink extends Model
{
    /** @use HasFactory<StatusPageLinkFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * How long the emailed link works for (PRD §9, verbatim).
     *
     * *"Status page magic links expire in 30 minutes, single use."* Short
     * because it is the credential that travels through email, which is a
     * channel this product does not control (ADR 0003).
     */
    public const LINK_MINUTES = 30;

    /**
     * How long the session it establishes lasts.
     *
     * #110 asks for this to be **a decision**, not an accident: *"a strictly
     * single-use 30-minute link means a client who reopens the page an hour
     * later is locked out — which is a support call to the agent."*
     *
     * Fourteen days, and the number comes from what a client does. PRD §3.3:
     * they check in *"once every seven years, in the middle of the largest
     * transaction of their life"* — in practice a handful of times over the
     * weeks a deal runs, often from the same phone, often from the same email
     * they were sent a fortnight ago. A day would put them back in the support
     * call; the life of the deal would make a forwarded link a permanent key
     * to somebody's transaction long after it closed.
     *
     * The session is revocable, which is what makes fourteen days acceptable
     * rather than merely convenient: an agent who has told the wrong person
     * has a control, and S64 renders the result.
     */
    public const SESSION_DAYS = 14;

    /** The bytes behind a token. 32 gives 256 bits, base62-encoded by the issuer. */
    public const TOKEN_BYTES = 32;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The same hash `TeamInvitation` uses, for the same reason and with the
     * same shape — sha256 of the plaintext, hex, 64 characters.
     *
     * Not `bcrypt`: a lookup has to find the row *from* the token, so the hash
     * must be deterministic. That is safe here because the token is 256 bits
     * of `random_bytes` rather than something a person chose — there is no
     * dictionary to run against it.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<TeamMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TeamMembership::class, 'team_membership_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'revoked_by')->withTrashed();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Can the emailed link still establish a session? */
    public function linkIsLive(): bool
    {
        return ! $this->isRevoked()
            && $this->used_at === null
            && $this->expires_at->isFuture();
    }

    /** Can the session it established still read the page? */
    public function sessionIsLive(): bool
    {
        return ! $this->isRevoked()
            && $this->session_token_hash !== null
            && $this->session_expires_at?->isFuture() === true;
    }

    /**
     * Why S64 is being shown, in the words that screen distinguishes.
     *
     * Screen Inventory gives S64 four states — expired, already used, revoked,
     * and *request a new one* — and they are four different sentences to a
     * client. Deciding which **here** rather than in the controller keeps the
     * one place that knows the columns as the one place that names the
     * reasons.
     *
     * Ordered by what is most true: a revoked link is revoked whether or not
     * it also expired, and *used* is the ordinary case that brings somebody
     * back to an old email.
     */
    public function refusalReason(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->used_at !== null => 'used',
            default => 'expired',
        };
    }

    /**
     * Live links for this deal and this person, newest first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
