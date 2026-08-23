<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A factory may set attributes the application refuses to mass-assign.
 *
 * `team_id` and `token_hash` are deliberately absent from every model's
 * fillable list — a request body must never choose a tenant, and an invitation
 * token is a credential. Laravel's factories build the model through the
 * constructor, which respects that list and **silently drops** what it cannot
 * fill, so a factory setting either one produces a row with a null column and
 * a confusing constraint violation.
 *
 * Forcing here rather than widening `$fillable` keeps the guarantee where it
 * matters (the HTTP boundary) and the convenience where it matters (a test
 * that needs a specific tenant). The `BelongsToTeam` guard still runs on save,
 * so a factory cannot smuggle a row into the wrong team either.
 */
trait ForcesAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Model
    {
        $model = $this->modelName();

        return (new $model)->forceFill($attributes);
    }
}
