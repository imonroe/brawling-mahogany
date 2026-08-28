<?php

declare(strict_types=1);

namespace App\Support\Extraction\Prompts;

use App\Enums\ExtractionKind;
use App\Support\Extraction\Contracts\ExtractionPrompt;

/**
 * Turn an inspection report into a proposed task list (F10.3 · issue #117).
 *
 * ## This prompt optimises for the opposite thing to `ContractPrompt`
 *
 * #117 names the risk precisely: *"An inspection report contains dozens of
 * findings, most of which are not worth a task. A tool that creates sixty tasks
 * has made the day worse."* And it names step 3 — cutting the trivia — as **the
 * feature**, not the overhead.
 *
 * So where the contract prompt is told to report everything and let a person
 * cut, this one is told to be selective and to say what it left out. The
 * asymmetry is deliberate and traces straight to consequence: a missed
 * inspection *deadline* is a legal problem, and a missed inspection *finding*
 * about a loose handrail is a line in a report that the agent is reading
 * anyway.
 *
 * Bump `version()` on any edit; see `ContractPrompt` for why.
 */
final class InspectionPrompt implements ExtractionPrompt
{
    public function kind(): ExtractionKind
    {
        return ExtractionKind::Inspection;
    }

    public function version(): string
    {
        return 'inspection-2026-08-28';
    }

    public function system(): string
    {
        return <<<'TEXT'
        You read residential property inspection reports and propose the short list
        of things somebody actually has to do about them. You are a reading tool,
        not an inspector and not an adviser: you never assess whether a finding is
        serious in engineering terms, never estimate a cost, and never recommend
        whether to object.

        Everything you propose must come from a finding in the document you are
        given. You do not add the things an inspection of this kind usually finds.

        You answer with JSON and nothing else. No preamble, no explanation, no code
        fence.
        TEXT;
    }

    public function instructions(string $documentText): string
    {
        return <<<TEXT
        Read the inspection report below and propose the tasks a real estate agent
        or transaction coordinator would raise from it.

        Answer with a JSON object of this exact shape:

        {
          "tasks": [
            {
              "title": "Get a licensed electrician to quote the panel replacement",
              "detail": "Report finds a Federal Pacific panel in the garage and recommends replacement.",
              "severity": "material",
              "confidence": 0.9,
              "page": 12,
              "quote": "Electrical panel is a Federal Pacific Stab-Lok..."
            }
          ],
          "omitted": 34
        }

        Rules:

        1. **Propose the material findings and leave the rest.** A task is worth
           proposing when somebody has to act on it before a deadline: a safety
           issue, a system at or past the end of its life, active water, anything
           the report itself flags for further evaluation by a specialist, and
           anything likely to become an objection.
        2. **Do not propose a task for ordinary wear, cosmetic notes, maintenance
           reminders, or a finding the report explicitly calls satisfactory.** A
           list of sixty tasks is worse than no list. Twelve is a lot. If you are
           unsure whether a finding clears the bar, leave it out — the person
           reviewing this has the report open.
        3. `omitted` is roughly how many findings you read and did not propose. It
           is shown to the reviewer so they know how much you left, and can go
           looking if the number surprises them.
        4. `title` is an instruction beginning with a verb, short enough to read in
           a list. `detail` is one sentence of context in plain language.
        5. `severity` is one of `safety`, `material`, or `minor`. Use `safety` only
           where the report says the finding is a hazard to people.
        6. `quote` is copied from the report exactly. It is shown beside your
           proposal so a person can check you. Do not paraphrase it.
        7. `confidence` is between 0 and 1 and is how certain you are that this is
           a real finding you have read correctly — not how serious it is.
        8. `page` is the page the finding appears on if the report marks pages, and
           null if it does not.
        9. Some values in the document may have been replaced with a marker like
           `[redacted: account number]`. That is deliberate. Ignore those and read
           everything else.

        Report follows.

        ---

        {$documentText}
        TEXT;
    }
}
