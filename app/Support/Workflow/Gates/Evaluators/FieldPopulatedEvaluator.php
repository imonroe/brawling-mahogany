<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Deal;
use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;
use Illuminate\Support\Str;

/**
 * A named field on the deal is filled in (issue #67).
 *
 * Config: `{"field": "transaction_value", "label": "Transaction value"}`.
 *
 * ## The allow-list is the point
 *
 * `config` is data a team edits on S43, so the field name is user input, and
 * user input naming an arbitrary attribute is a way to read anything the model
 * exposes. The gate can only ask about fields on this list, and a name that is
 * not on it is a **configuration error that refuses**, not a silent false —
 * a gate that quietly never clears is worse than one that says it is broken.
 *
 * Slice 2 lists the deal's own fields. Property fields join it with #61, and
 * that is the moment to extend this list rather than loosen the rule.
 */
final class FieldPopulatedEvaluator implements GateEvaluator
{
    /** @var array<string, string> */
    private const ASKABLE = [
        'transaction_value' => 'a transaction value',
        'opened_at' => 'an opened date',
        'closed_at' => 'a closing date',
        'notes' => 'notes',
        'name' => 'a name',
    ];

    public static function type(): string
    {
        return 'field_populated';
    }

    public static function label(): string
    {
        return 'Field populated';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $field = (string) ($gate->configuration()['field'] ?? '');

        if (! array_key_exists($field, self::ASKABLE)) {
            return GateVerdict::unmet(
                $field === ''
                    ? 'This gate does not say which field it is waiting for, so it cannot be checked.'
                    : "This gate is set to check “{$field}”, which is not a field a gate can ask about.",
                ['type' => 'gate_config', 'gate' => $gate->getKey()],
            );
        }

        /*
         * Walked one link at a time, because the chain can legitimately break.
         *
         * A soft-deleted deal, or a stage whose workflow went with it, leaves
         * a gate pointing at nothing — and `$gate->stage->workflow->deal`
         * turns that into a 500 on the advance screen. Every other refusal in
         * this evaluator explains itself; this one has no business being the
         * exception, least of all by throwing.
         */
        $deal = $gate->stage?->workflow?->deal;

        if (! $deal instanceof Deal) {
            return GateVerdict::unmet(
                'This gate is attached to a deal that no longer exists, so it cannot be checked.',
                ['type' => 'gate_config', 'gate' => $gate->getKey()],
            );
        }

        $value = $deal->getAttribute($field);
        $description = self::ASKABLE[$field];

        $filled = match (true) {
            $value === null => false,
            is_string($value) => trim($value) !== '',
            default => true,
        };

        $label = (string) ($gate->configuration()['label'] ?? Str::headline($field));

        if ($filled) {
            return GateVerdict::met("{$label} is filled in.");
        }

        return GateVerdict::unmet(
            "This deal has no {$description} yet.",
            ['type' => 'deal_field', 'deal' => $deal->getKey(), 'field' => $field],
        );
    }
}
