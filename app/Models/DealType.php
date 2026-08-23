<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealSide;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Tenancy\TeamContext;
use Database\Factories\DealTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What kind of transaction this is (PRD §4.3 F3.1, §7.6 · S76 · issue #58).
 *
 * A lookup, not a business table, and the distinction is why this carries no
 * `BelongsToTeam`. A null `team_id` is a **system default** every team gets;
 * a set one is a type a team wrote for itself. The global scope cannot express
 * "mine or everybody's", so `visibleTo()` does, and
 * `tests/Isolation/ModelTenancyConventionTest.php` carries the reason.
 *
 * ## Archived, never deleted
 *
 * S76's in-use warning is the pattern for every lookup in this product: you
 * cannot silently remove a value that live records point at. Deleting "Rental
 * Placement" would orphan every rental deal that ever used it. Archiving keeps
 * those deals labelled and takes the type out of every picker, which is what
 * somebody actually means when they try to delete one.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $name
 * @property DealSide $side
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'side', 'sort_order'])]
class DealType extends Model
{
    /** @use HasFactory<DealTypeFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => DealSide::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Which workflow templates this type offers (F4.1).
     *
     * @return BelongsToMany<WorkflowTemplate, $this>
     */
    public function workflowTemplates(): BelongsToMany
    {
        return $this->belongsToMany(WorkflowTemplate::class, 'deal_type_workflow_template')
            ->withPivot('is_default');
    }

    /**
     * Route binding sees only what this team may see (ADR 0002, layer 3).
     *
     * Layer 3 is explicit: *"a route-bound model whose `team_id` does not
     * match is a **404**, not a 403 — a 403 confirms the record exists, which
     * is itself a disclosure."* Every other table gets that for free, because
     * the global scope removes the row before the binder ever sees it and
     * `firstOrFail()` does the rest.
     *
     * `deal_types` has no global scope, deliberately — a null `team_id` means
     * "everybody's", which a scope cannot express — so the 404 has to be
     * written here. Without it the five routes answered 403 for "exists, not
     * yours" and 404 for "does not exist", which is a working existence oracle
     * over every deal-type id on the platform.
     *
     * A **system** row still resolves and is still refused by the policy with
     * a 403, and that is correct rather than an inconsistency: the actor can
     * genuinely see it — it is shared, it is on their screen — they simply may
     * not edit it. 403 discloses nothing they did not already know.
     *
     * `isUnscoped()` and `requireId()` mirror `TeamScope` exactly, so the
     * super-admin bypass and the no-team failure behave the way they do
     * everywhere else.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $context = app(TeamContext::class);

        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        if (! $context->isUnscoped()) {
            $query->visibleTo($context->requireId(static::class));
        }

        return $query->first();
    }

    /** A system default, shared by every team. */
    public function isSystem(): bool
    {
        return $this->team_id === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Types this team may choose from: its own, plus the system defaults.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, Team|string $team): Builder
    {
        $teamId = $team instanceof Team ? $team->getKey() : $team;

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('team_id')
            ->orWhere('team_id', $teamId));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Is this a row this team may edit or archive at all?
     *
     * **One fact, not two.** Editing and archiving are open under exactly the
     * same condition, and the first draft of S76 sent the screen two props
     * computed separately — implying a distinction that does not exist and
     * giving each a chance to drift from the policy. The policy reads this,
     * and so does the screen, so they cannot disagree.
     *
     * A system default is never either, by a team — it is not theirs, and
     * hiding "Rental Placement" for everybody because one team stopped doing
     * rentals is not what they asked for. (Taking a system type out of *one*
     * team's picker is a real want and a different feature; S76 does not have
     * it yet.) An archived one is not either: a rename there would free and
     * re-take a name behind the validator's back.
     */
    public function isManageableByTeam(): bool
    {
        return ! $this->isSystem() && ! $this->isArchived();
    }

    /**
     * Ours, or the shared kind — the one spelling of that question.
     *
     * `Deal::guardDealType()` asks it to decide whether to throw
     * `ForeignReferenceException`, and `DealDraft::dealType()` asks it to
     * decide whether a resumed wizard still has an answer to step one. Written
     * once so the two cannot drift: a system type has `team_id = null` and
     * belongs to everybody, which is the whole reason no composite foreign key
     * can express this and something in PHP has to.
     */
    public function belongsToTeamOrEverybody(?string $teamId): bool
    {
        return $this->isSystem() || $this->team_id === $teamId;
    }

    /**
     * Whether a **new** deal may be opened on this type.
     *
     * Both halves, because S76's archive dialog promises *"no new deal will be
     * able to use it"* and a foreign type was never offered in the first
     * place. `Deal::guardDealType()` refuses the same two cases with two
     * different exceptions, because it has to say which; a picker only has to
     * know whether to show the row.
     */
    public function isSelectableBy(?string $teamId): bool
    {
        return $this->belongsToTeamOrEverybody($teamId) && ! $this->isArchived();
    }

    /** The mirror image, and the only thing an archived row still allows. */
    public function isRestorable(): bool
    {
        return ! $this->isSystem() && $this->isArchived();
    }

    /**
     * Deals **this team** has on this type — what S76's warning counts.
     *
     * Every one of them, whatever state it is in. A cancelled deal and a
     * fell-through deal still render with their type and still orphan if the
     * type goes, so an "in use" count that quietly dropped them would
     * understate the thing it exists to warn about. (It was called
     * `liveDealCount` and counted all states, which was a name promising a
     * filter that was not there.) Soft-deleted deals are excluded, because
     * those are already on their way out under the retention purge.
     *
     * Scoped, and the unscoped version was a leak. A system deal type is
     * shared by every team on the platform, so counting without the scope
     * would have answered "how many deals does everybody have" and shown that
     * number to one team. It is also the wrong question: a team deciding
     * whether to archive a type means "am *I* still using this".
     */
    public function dealCount(): int
    {
        return Deal::query()
            ->where('deal_type_id', $this->getKey())
            ->count();
    }
}
