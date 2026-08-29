<?php

declare(strict_types=1);

namespace App\Support\Extraction\Prompts;

use App\Enums\ExtractionKind;
use App\Support\Extraction\Contracts\ExtractionPrompt;

/**
 * Read a purchase contract's dates and provisions (F10.1 · issue #115).
 *
 * ## Bump `version()` on any edit below
 *
 * `extractions.prompt_version` is what #118's harness reports against, and
 * `tests/Unit/ExtractionPromptVersionTest.php` hashes the text so an edit that
 * forgets is a failing build rather than a scorecard that silently compares two
 * different prompts.
 *
 * ## What the instructions are trying to prevent, and why each line is there
 *
 * PRD §12.3 gives this one metric with **zero tolerance**: a critical date
 * missed entirely. That shapes the whole instruction. A model asked to be
 * careful returns fewer proposals; a model asked to be complete returns more
 * and is wrong more often. The second failure is the survivable one — an
 * over-proposed date is a rejection on S66, and a missed inspection deadline is
 * a legal problem. So the prompt asks for **completeness with honest
 * confidence**, and the review screen absorbs the cost.
 *
 * The other three rules exist because of what happens downstream:
 *
 * - **Never invent a date that is not in the document.** The single most
 *   expensive failure mode, because a fabricated deadline arrives on S66
 *   looking exactly like a real one.
 * - **Quote the passage verbatim.** Screen Inventory: the source *"is not a
 *   link to check later; it is on screen next to the value."* A paraphrase
 *   makes the one control standing between a model and a live contingency
 *   calendar uncheckable.
 * - **Resolve offsets, and say you did.** A Colorado contract writes half its
 *   deadlines as *"MEC + 10 days"*. Returning that string would push the
 *   arithmetic onto a person doing eleven of them in five minutes, which is
 *   the target PRD §12.3 sets.
 */
final class ContractPrompt implements ExtractionPrompt
{
    public function kind(): ExtractionKind
    {
        return ExtractionKind::Contract;
    }

    public function version(): string
    {
        return 'contract-2026-08-28';
    }

    public function system(): string
    {
        return <<<'TEXT'
        You read residential real estate purchase contracts and report the dates,
        deadlines and notable provisions they contain. You are a reading tool, not
        an adviser: you never interpret the contract's legal effect, never give an
        opinion on its terms, and never fill in what a contract of this type would
        usually say.

        Everything you report must be present in the document you are given. If a
        deadline is not in the document, it is not in your answer — an omission is
        recoverable and an invention is not, because a person reviewing your output
        cannot tell a fabricated deadline from a real one.

        You answer with JSON and nothing else. No preamble, no explanation, no code
        fence.
        TEXT;
    }

    public function instructions(string $documentText): string
    {
        return <<<TEXT
        Read the contract below and report every date and deadline in it, plus any
        additional provisions worth a person's attention.

        Answer with a JSON object of this exact shape:

        {
          "dates": [
            {
              "label": "Inspection Objection Deadline",
              "value": "2026-03-28",
              "critical": true,
              "confidence": 0.95,
              "page": 3,
              "quote": "Inspection Objection Deadline .................. March 28, 2026",
              "derivation": "MEC + 10 days"
            }
          ],
          "provisions": [
            {
              "summary": "Seller conveys the washer and dryer with the property.",
              "confidence": 0.9,
              "page": 8,
              "quote": "Seller shall convey the existing washer and dryer..."
            }
          ]
        }

        Rules:

        1. `value` is always a calendar date as YYYY-MM-DD. If the contract states
           a deadline as an offset — "MEC + 10 days", "ten days after Mutual
           Execution" — work out the calendar date from the acceptance or execution
           date in the document, put that date in `value`, and put the offset you
           were given in `derivation`. If you cannot find the date the offset is
           measured from, still report the row with your best reading and a low
           `confidence`, and say so in `derivation`.
        2. `quote` is copied from the document exactly, including its spacing and
           any misreadings in it. Do not tidy it, and do not paraphrase. It is
           shown to a person beside your proposal so they can check you.
        3. `critical` is true for a deadline whose being missed has legal or
           financial consequence: inspection objection and resolution, financing or
           loan objection, appraisal, title objection and resolution, and closing.
           Everything else is false.
        4. `confidence` is between 0 and 1 and is your own honest reading of how
           certain you are that this row is right. Report a low-confidence row
           rather than dropping it — a person will decide. Do not use confidence to
           express how important the date is.
        5. `page` is the page number the value appears on if the document marks
           pages, and null if it does not.
        6. A provision is something a person would want to know that is not a date:
           an inclusion or exclusion, a seller concession, a repair obligation, an
           unusual contingency. Ordinary boilerplate is not a provision. If there
           are none, return an empty list.
        7. Report every date you find, including ones you are unsure about. Missing
           a deadline is the worst thing you can do here.
        8. Some values in the document may have been replaced with a marker like
           `[redacted: account number]`. That is deliberate and is not an error.
           Ignore those and read everything else.

        Contract follows.

        ---

        {$documentText}
        TEXT;
    }
}
