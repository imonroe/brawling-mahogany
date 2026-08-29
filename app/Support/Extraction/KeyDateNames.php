<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Models\KeyDate;
use Illuminate\Support\Collection;

/**
 * Deciding whether a proposal is *the same deadline* a deal already has.
 *
 * ## Why this needs deciding at all
 *
 * #116 names *"conflict with existing"* as a key state: the deal has an
 * inspection deadline and the model proposes a different one. Without an
 * answer to "is this the same deadline?", confirming would add a second row
 * called *Inspection Objection Deadline* beside the *Inspection objection*
 * somebody typed last week — two dates, both live, both feeding reminders, and
 * nothing on any screen saying they are about the same thing. That is worse
 * than either date being wrong.
 *
 * ## One definition, read by both the detector and the writer
 *
 * `App\Queries\DealExtraction` uses this to draw the conflict strip and the
 * cascade preview; `ConfirmExtractedField` uses it to decide whether it is
 * adding a date or moving one. CLAUDE.md's rule for the cascade applies
 * exactly: *"the preview and the save are the same function or they are two
 * answers"* — a screen that warned about a conflict the writer then did not
 * see would promise one thing and do another.
 *
 * ## Normalisation, and what it deliberately does not attempt
 *
 * Case, punctuation and the noise words a form adds — *deadline*, *date*, *by*
 * — are removed, so `Inspection Objection Deadline` and `Inspection objection`
 * are one key. Synonyms are **not**: *Loan objection* and *Financing objection*
 * stay different, and *Closing* and *Settlement* stay different. A synonym
 * table would be a list of Colorado's vocabulary maintained by guesswork, and
 * getting it wrong in the generous direction silently overwrites a date
 * somebody typed. A missed conflict costs a duplicate row a person can see and
 * delete; a false one costs a deadline.
 */
final class KeyDateNames
{
    /** Words a form adds and a person drops. */
    private const NOISE = ['deadline', 'date', 'dates', 'by', 'the', 'of', 'on'];

    public static function key(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array($word, self::NOISE, true),
        ));

        return implode('', $words);
    }

    /**
     * The existing date this proposal is about, if there is one.
     *
     * @param  Collection<int, KeyDate>|iterable<KeyDate>  $existing
     */
    public static function match(string $label, iterable $existing): ?KeyDate
    {
        $key = self::key($label);

        if ($key === '') {
            return null;
        }

        foreach ($existing as $keyDate) {
            if (self::key($keyDate->name) === $key) {
                return $keyDate;
            }
        }

        return null;
    }
}
