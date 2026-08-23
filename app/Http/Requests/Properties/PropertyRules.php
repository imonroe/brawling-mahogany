<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Support\Links\SafeUrl;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The rules S37 collects, shared by create and edit (issue #61).
 *
 * One trait rather than two copies, for the reason `DealTypeRules` exists: the
 * create and edit forms are the same form, and two rule sets drift within a
 * month — usually in the direction of the edit path being looser.
 */
trait PropertyRules
{
    /**
     * Normalise before the rules run, so what is judged is what is stored.
     *
     * A trait's `prepareForValidation()` is picked up by the form request the
     * same way its rules are; both requests using this trait get it.
     */
    protected function prepareForValidation(): void
    {
        $state = $this->input('state_code');

        if (is_string($state) && trim($state) !== '') {
            $this->merge(['state_code' => Str::upper(trim($state))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function propertyRules(?Property $property = null): array
    {
        return [
            /*
             * Every address part is optional, and that is not laziness.
             * A property is created from a parcel number before the street is
             * known, and from a street before the ZIP is looked up. Requiring
             * a full address would mean somebody typing a placeholder, and a
             * placeholder in an address field is worse than an empty one.
             */
            'street' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:255'],

            /*
             * Two ASCII letters, upper-cased before it is stored.
             *
             * The column is two characters, so a silently truncated state is
             * a wrong address — and `alpha` without `:ascii` matches any
             * Unicode letter, which is not a postal abbreviation.
             *
             * The case is normalised in `prepareForValidation()` rather than
             * at render time, for the same reason the address is sent as
             * parts: IA §10 says "City, ST ZIP", and `co` typed once would
             * otherwise render as `Boulder, co 80302` on every screen that
             * ever shows it.
             */
            'state_code' => ['nullable', 'string', 'size:2', 'alpha:ascii'],
            'postal_code' => ['nullable', 'string', 'max:16'],

            /*
             * Unique per team, matching `properties_team_parcel_unique`
             * predicate for predicate: the team, `deleted_at IS NULL`, and
             * `lower(parcel_number)`.
             *
             * The rule exists because the index does. Shipping a partial
             * unique index with no matching rule has happened three times in
             * this repository (#143, #145, #148), and each time the symptom
             * was a 500 where a sentence belonged.
             */
            'parcel_number' => ['nullable', 'string', 'max:64', $this->parcelIsFree($property)],

            'type' => ['required', Rule::enum(PropertyType::class)],
            'status' => ['required', Rule::enum(PropertyStatus::class)],

            // Bounded because the columns are. `unsignedSmallInteger` tops out
            // at 65535, and a database-level range error is a 500.
            'beds' => ['nullable', 'integer', 'min:0', 'max:99'],
            /*
             * `decimal:0,1`, because the column is `decimal(3, 1)`.
             *
             * `numeric` accepted `2.55` and Postgres stored `2.6` — a value
             * quietly becoming a different value, which is the surprise the
             * migration's argument for a decimal column says it is avoiding.
             * `max:99` keeps it inside `decimal(3, 1)`'s 99.9.
             */
            'baths' => ['nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:99'],
            'sqft' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'year_built' => ['nullable', 'integer', 'min:1600', 'max:'.(date('Y') + 5)],

            'notes' => ['nullable', 'string', 'max:10000'],

            ...$this->linkRules(),
        ];
    }

    /**
     * A team cannot have two live properties on one parcel number.
     *
     * Hand-written rather than `Rule::unique`, and the reason is the one
     * `Settings\DealTypeRules` already records: `Rule::unique` compares with
     * `=`, and `properties_team_parcel_unique` is over
     * `lower(parcel_number)`. Adding a `whereRaw` beside `Rule::unique` does
     * not help — the `=` clause is still there and still misses, so the rule
     * passed `12-345-67A` against a stored `12-345-67a` and the constraint
     * caught it instead. That landed on the right field only because
     * `SaveProperty` has a `try/catch` written for the race window; a rule gap
     * riding on a race handler is one edit away from a stack trace.
     *
     * `lower(?)` rather than a PHP-folded bind, for the same reason again:
     * `mb_strtolower()` and Postgres `lower()` disagree on real input (`ΑΣ`,
     * `İ`), and only Postgres agrees with Postgres.
     */
    private function parcelIsFree(?Property $property): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($property): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (self::parcelIsTaken($value, $property)) {
                $fail('Another property already has this parcel number.');
            }
        };
    }

    /**
     * The index's question, asked in the index's terms.
     *
     * Normalised through `Property::normaliseParcel()`, which is the same
     * function the column's mutator uses — so what is asked about and what is
     * written cannot drift. They did: this trimmed and the write did not, and
     * a trailing space was invisible to the rule *and* to `lower()`.
     */
    public static function parcelIsTaken(mixed $parcel, ?Property $ignoring = null): bool
    {
        $parcel = Property::normaliseParcel($parcel);

        if ($parcel === null) {
            return false;
        }

        $query = DB::table('properties')
            ->whereNull('deleted_at')
            ->where('team_id', app(TeamContext::class)->requireId(Property::class))
            ->whereRaw('lower(parcel_number) = lower(?)', [$parcel]);

        if ($ignoring instanceof Property) {
            $query->where('id', '!=', $ignoring->getKey());
        }

        return $query->exists();
    }

    /**
     * The external links S37 edits inline (PRD §7.13).
     *
     * `sometimes` rather than `required`, and the difference carries meaning:
     * an empty list is an instruction — remove them all — which `required`
     * would refuse, while a request that omits `links` entirely leaves them
     * alone. `UpdatePropertyRequest` reads that second case by presence.
     *
     * @return array<string, mixed>
     */
    private function linkRules(): array
    {
        return [
            /*
             * `list`, and that word is load-bearing.
             *
             * The loop that stores these uses its own index as `sort_order`,
             * and `array` alone let a JSON body choose the keys:
             * `{"links": {"zz": {…}}}` passed every rule and put `"zz"` into
             * an `unsignedSmallInteger`. A validated body producing a stack
             * trace where a sentence belonged is the shape this repository
             * has now shipped three times.
             */
            'links' => ['sometimes', 'array', 'max:50', 'list'],

            /*
             * `distinct`, because an id may be claimed once. Repeating one
             * made the second row overwrite the first and stored one link
             * where two were sent, with no error.
             */
            'links.*.id' => ['nullable', 'string', 'distinct'],
            'links.*.label' => ['required', 'string', 'max:255'],

            /*
             * The scheme allowlist, not a `url` rule.
             *
             * `url` asks whether a string parses. `javascript:alert(1)` parses
             * and is script execution in the reader's session once it is an
             * `href`; Laravel's `url` rule accepts it. `SafeUrl` carries the
             * allowlist and `ExternalLink` refuses the same values on save,
             * because the next writer is #62's screen and not this request.
             */
            'links.*.url' => [
                'required', 'string', 'max:2048',
                /*
                 * The same URL twice in one payload is what
                 * `external_links_unique_url` refuses, named by the validator
                 * rather than reached through a constraint violation — a
                 * partial index with no matching rule is the defect this
                 * repository keeps finding.
                 *
                 * Not a claim of predicate-for-predicate parity, which would
                 * be false: `distinct:ignore_case` folds with
                 * `mb_strtolower()` and the index folds with Postgres
                 * `lower()`, and the two disagree on input no URL realistically
                 * carries. `SaveProperty` still catches the violation, so the
                 * exotic pair gets a sentence rather than a stack trace — it
                 * just gets it one layer later.
                 */
                'distinct:ignore_case',
                fn (string $attribute, mixed $value, callable $fail) => SafeUrl::permits(
                    is_string($value) ? $value : null,
                ) ? null : $fail(SafeUrl::message()),
            ],
        ];
    }
}
