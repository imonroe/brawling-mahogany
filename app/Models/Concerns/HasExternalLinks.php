<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ExternalLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Links out, for a record that can carry them (PRD §7.13 · issue #61).
 *
 * The trait is what a model uses to opt in; `ExternalLink::LINKABLE` is what
 * the guard checks. Two lists rather than one, on purpose: the trait cannot
 * refuse a `linkable_type` written straight into the column by something that
 * never touched the model, and that column is the thing the guard has to
 * validate. `tests/Unit/ExternalLinkConventionTest.php` keeps the two in step.
 *
 * Every linkable soft-deletes — `HasProductDefaults` brings it, and the
 * `deleting` hook below depends on it. `ExternalLinkConventionTest` holds
 * that, along with the team requirement.
 *
 * @phpstan-require-extends Model
 */
trait HasExternalLinks
{
    // The `deleting` hook below calls `isForceDeleting()`. Declared rather
    // than assumed, so a linkable cannot be added without it.
    use SoftDeletes;

    /**
     * Links go when their record goes.
     *
     * There is no foreign key to cascade — that is the whole point of a
     * polymorphic pointer, and the reason ADR 0002 gives this table its own
     * section. So the parent has to do it, and doing it here rather than in
     * `PropertyController::destroy()` is the same lesson that section already
     * records: a rule written into one caller is a rule the second caller is
     * written without, and #62 makes deals linkable.
     *
     * **Soft, matching the parent.** `records:purge` finds a row by its
     * `deleted_at`, so a link left live when its property was soft-deleted was
     * never swept — and once the purge hard-deleted the property there was
     * nothing left pointing at it and no way to find it. Permanent, orphaned,
     * and past PRD §9's *"then hard delete"*.
     *
     * A **force** delete takes them with it for the same reason. Nothing
     * force-deletes a property today; `records:purge` uses table-level deletes
     * that skip model events entirely, and by then these rows carry their own
     * aged `deleted_at` and are swept on their own account.
     */
    public static function bootHasExternalLinks(): void
    {
        static::deleting(function (self $model): void {
            $model->externalLinks()->get()->each(
                fn (ExternalLink $link) => $model->isForceDeleting()
                    ? $link->forceDelete()
                    : $link->delete(),
            );
        });
    }

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
