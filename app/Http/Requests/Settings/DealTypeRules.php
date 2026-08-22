<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\DealSide;
use App\Models\DealType;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The two fields S76 collects, in one place so create and edit cannot drift.
 *
 * `sort_order` is deliberately absent: it is a position in a picker, not
 * something somebody types into a form. The controller assigns it.
 */
trait DealTypeRules
{
    /**
     * @return array<string, mixed>
     */
    protected function dealTypeRules(?DealType $ignoring = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->uniqueWithinTeam($ignoring)],
            'side' => ['required', Rule::enum(DealSide::class)],
        ];
    }

    /**
     * A team cannot have two live types with the same name — and cannot shadow
     * a system default's name either.
     *
     * Hand-written rather than `Rule::unique`, for the reason
     * `People\PersonRules` records: `Rule::unique` compares with `=`, and both
     * indexes on this table are over `lower(name)`. A rule that matched only
     * because a mutator folded would be matching the mutator.
     *
     * The shadowing half is the part worth arguing for. Nothing in the schema
     * forbids a team calling its own type "Buyer Representation" — the two
     * partial indexes are separate, one for system rows and one per team — but
     * the picker would then show the same words twice with no way to tell them
     * apart, and the deal that came back would depend on which one was
     * clicked. The database cannot express "unique across mine and the shared
     * ones"; this can.
     *
     * ## Both halves have to match the index, and neither did
     *
     * **The comparison.** `lower(name) = lower(?)`, so Postgres folds both
     * sides. Folding the right side in PHP instead was the same defect the
     * paragraph above sets out to avoid, one layer over: `mb_strtolower()`
     * and Postgres `lower()` are different functions and disagree on real
     * input. `ΑΣ` folds to `ας` in PHP (final sigma) and `ασ` in Postgres, and
     * `İ` to `i̇` and `i` — so a duplicate slipped past the rule and hit the
     * index as a 500, and two names Postgres considered distinct were refused
     * as the same. ASCII agrees, which is why both original tests passed.
     *
     * **The predicates.** `archived_at IS NULL` as well as `deleted_at`,
     * because both indexes are partial on both — and because the migration
     * says why in so many words: *"the whole point of archiving is that the
     * name is free again."* Without it, archiving the wrong type and starting
     * clean was refused by a rule pointing at a row rendered on the same
     * screen with an "Archived" badge and no explanation.
     */
    private function uniqueWithinTeam(?DealType $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (self::nameIsTaken(trim($value), $ignoring)) {
                $fail('You already have a deal type with this name.');
            }
        };
    }

    /**
     * The index's question, asked in the index's terms.
     *
     * Static and shared, because `DealTypeController::restore()` has to ask it
     * too — clearing `archived_at` moves the row back *into* the partial index,
     * so a restore can violate it exactly the way a create can. One
     * implementation, or the two drift and the one nobody tested is the one
     * that 500s.
     */
    public static function nameIsTaken(string $name, ?DealType $ignoring = null): bool
    {
        $query = DB::table('deal_types')
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->where(fn ($inner) => $inner
                ->whereNull('team_id')
                ->orWhere('team_id', app(TeamContext::class)->requireId(DealType::class)))
            // `lower(?)`, not a PHP-folded bind: the left side is Postgres
            // `lower()` and only Postgres agrees with Postgres.
            ->whereRaw('lower(name) = lower(?)', [$name]);

        if ($ignoring instanceof DealType) {
            $query->where('id', '!=', $ignoring->getKey());
        }

        return $query->exists();
    }
}
