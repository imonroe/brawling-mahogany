<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use App\Support\Tenancy\CrossTenantException;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforcement layer 1 (ADR 0002): a global scope that fails closed.
 *
 * The point is the failure mode. A developer who forgets a `where` gets *no
 * rows*, not *everybody's rows* — and a developer who runs a scoped query with
 * no team resolved at all gets an exception, because an empty result reads as
 * "nothing here yet" to everybody who sees it.
 *
 * The trait also fills `team_id` on create and refuses to write a row into a
 * team other than the resolved one, which closes the other half of the gap:
 * the scope protects reads, and this protects writes.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function (Model $model): void {
            $context = app(TeamContext::class);

            if ($model->getAttribute('team_id') === null) {
                // Never infer a team from "the last one seen". Either one is
                // resolved or this is a bug worth stopping for.
                $model->setAttribute('team_id', $context->requireId($model::class));

                return;
            }

            self::guardTeam($model, $context);
        });

        static::updating(function (Model $model): void {
            self::guardTeam($model, app(TeamContext::class));
        });
    }

    /**
     * Refuse a write aimed at a team other than the resolved one.
     *
     * The super-admin bypass is allowed through: it is the one caller that
     * legitimately acts inside a team it is not a member of, and it is audited
     * where it is used rather than silently here.
     */
    protected static function guardTeam(Model $model, TeamContext $context): void
    {
        if ($context->isUnscoped()) {
            return;
        }

        $resolved = $context->id();
        $attempted = $model->getAttribute('team_id');

        if ($resolved === null || $attempted === null || $resolved === $attempted) {
            return;
        }

        throw CrossTenantException::forWrite($model::class, $resolved, (string) $attempted);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Lift the scope for one query.
     *
     * ADR 0002: *"`withoutTeamScope()` exists for exactly two callers — the
     * super-admin console and the console commands that operate across teams
     * — and both are audited."* It is spelled out rather than aliased to
     * `withoutGlobalScope` so that grepping for it finds every use.
     *
     * @return Builder<static>
     */
    public static function withoutTeamScope(): Builder
    {
        return static::query()->withoutGlobalScope(TeamScope::class);
    }
}
