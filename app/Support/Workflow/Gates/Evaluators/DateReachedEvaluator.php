<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * A named key date has passed — **Slice 4** (issue #109).
 *
 * The `key_dates` table is Slice 4 (#106), and evaluating against a date this
 * gate cannot see would be guessing. See `DocumentPresentEvaluator`.
 */
final class DateReachedEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'date_reached';
    }

    public static function label(): string
    {
        return 'Date reached';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $date = (string) ($gate->configuration()['key_date'] ?? 'a key date');

        return GateVerdict::notYetWired(
            "This stage is waiting for {$date} to pass, and dates and deadlines arrive in Slice 4.",
            '#109',
        );
    }
}
