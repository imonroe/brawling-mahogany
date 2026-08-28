<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ExtractionKind;
use App\Support\Extraction\Contracts\ExtractionPrompt;
use App\Support\Extraction\Prompts\ContractPrompt;
use App\Support\Extraction\Prompts\InspectionPrompt;

/**
 * Which prompt a kind uses.
 *
 * A `match` over two cases, and it is a class rather than a line inside
 * `PerformExtraction` for the same reason `GateRegistry` is: the mapping is the
 * thing #118's harness needs to enumerate. Scoring a prompt change means
 * running *every* prompt, and a harness that has to reach into the worker to
 * find them will miss the third one somebody adds.
 */
final class PromptRegistry
{
    public function for(ExtractionKind $kind): ExtractionPrompt
    {
        return match ($kind) {
            ExtractionKind::Contract => new ContractPrompt,
            ExtractionKind::Inspection => new InspectionPrompt,
        };
    }

    /** @return list<ExtractionPrompt> */
    public function all(): array
    {
        return array_map(
            fn (ExtractionKind $kind): ExtractionPrompt => $this->for($kind),
            ExtractionKind::cases(),
        );
    }
}
