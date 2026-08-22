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
     * A team cannot have two types with the same name — and cannot shadow a
     * system default's name either.
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
     */
    private function uniqueWithinTeam(?DealType $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $query = DB::table('deal_types')
                ->whereNull('deleted_at')
                ->where(fn ($inner) => $inner
                    ->whereNull('team_id')
                    ->orWhere('team_id', app(TeamContext::class)->requireId(DealType::class)))
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))]);

            if ($ignoring instanceof DealType) {
                $query->where('id', '!=', $ignoring->getKey());
            }

            if ($query->exists()) {
                $fail('You already have a deal type with this name.');
            }
        };
    }
}
