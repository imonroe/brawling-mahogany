<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Enforcement layer 1 (ADR 0002): the global scope itself.
 *
 * In its own class so `withoutGlobalScope(TeamScope::class)` has something to
 * name, and so the one place a query becomes team-constrained is a file you
 * can open rather than a closure inside a trait.
 *
 * @implements Scope<Model>
 */
final class TeamScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TeamContext::class);

        if ($context->isUnscoped()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('team_id'),
            $context->requireId($model::class),
        );
    }
}
