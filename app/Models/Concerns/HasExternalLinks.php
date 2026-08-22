<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ExternalLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Links out, for a record that can carry them (PRD §7.13 · issue #61).
 *
 * The trait is what a model uses to opt in; `ExternalLink::LINKABLE` is what
 * the guard checks. Two lists rather than one, on purpose: the trait cannot
 * refuse a `linkable_type` written straight into the column by something that
 * never touched the model, and that column is the thing the guard has to
 * validate. `tests/Unit/ExternalLinkConventionTest.php` keeps the two in step.
 *
 * @phpstan-require-extends Model
 */
trait HasExternalLinks
{
    /**
     * In the order S37 put them. The first link is the one somebody meant.
     *
     * @return MorphMany<ExternalLink, $this>
     */
    public function externalLinks(): MorphMany
    {
        return $this->morphMany(ExternalLink::class, 'linkable')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }
}
