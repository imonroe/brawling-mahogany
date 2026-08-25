<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Messages\MergeFields;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * F5.6's *"validated at save time"*, enforced (issue #90).
 *
 * > **Validated at save time.** An invalid merge field is a broken email to a
 * > real client, discovered too late.
 *
 * Applied per field rather than once over the whole template, so the error
 * lands on the box the mistake is in. A single form-level error saying "an
 * invalid merge field" leaves somebody hunting through three text areas.
 *
 * ## It refuses three different things, and the third is the one that hides
 *
 *  1. **A token nothing answers to** — a typo, or a field renamed since.
 *  2. **A field that exists and cannot resolve yet** — key dates and the
 *     status page link arrive in Slice 4. Named with its slice rather than
 *     called unknown, because "there is no such field" would be a lie that
 *     sends somebody looking for a spelling mistake.
 *  3. **A malformed token** — `{{ client name }}`, `{{}}`, `{{ Client_Name }}`.
 *     These are the dangerous ones: they are not tokens at all, so a
 *     validator that scanned for *well-formed* tokens and checked those
 *     against the registry would see nothing wrong and let the braces through
 *     into somebody's inbox. {@see MergeFields::extract()} is deliberately
 *     loose for this reason.
 */
final readonly class ValidMergeFields implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        foreach (MergeFields::extract($value) as $token) {
            if (! MergeFields::isWellFormed($token)) {
                $fail(sprintf(
                    '“%s” is not a merge field. Merge fields look like {{ client_name }} — pick one from the list.',
                    $token === '' ? '{{ }}' : '{{ '.$token.' }}',
                ));

                continue;
            }

            $field = MergeFields::find($token);

            if ($field === null) {
                $fail(sprintf(
                    'There is no merge field called {{ %s }}. Pick one from the list.',
                    $token,
                ));

                continue;
            }

            if (! $field->isAvailable()) {
                $fail(sprintf('{{ %s }} cannot be filled in yet. %s', $token, $field->availableFrom));
            }
        }
    }
}
