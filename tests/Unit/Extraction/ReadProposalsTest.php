<?php

declare(strict_types=1);

use App\Enums\ExtractedFieldType;
use App\Enums\ExtractionKind;
use App\Support\Extraction\Proposal;
use App\Support\Extraction\ProviderFailed;
use App\Support\Extraction\ReadProposals;

/**
 * The boundary between a system with rules and one that produces plausible text
 * (#115 · PRD §4.10, §8.4).
 *
 * `ReadProposals` is the seam where a model's answer stops being words and
 * starts being rows on a review screen, and every rule in it is written about
 * the same failure: *"a lenient reader writes garbage into `extracted_fields`
 * and a person on S66 confirms it because it looked like everything else on the
 * screen."*
 *
 * Pure by construction, which is the whole reason the class exists separately
 * from the provider and from `PerformExtraction`: it is a function over a
 * response body, so it can be held to all of this without a database, a team,
 * or an HTTP call.
 */

/**
 * The Messages API envelope, with the answer inside it.
 *
 * Built here rather than in each case because the envelope is the provider's
 * shape and not the reader's subject — a test that spelled out `content` and
 * `type` fourteen times would be fourteen places to change when a second
 * provider arrives.
 *
 * @param  array<string, mixed>|string  $answer
 * @return array<string, mixed>
 */
function providerAnswer(array|string $answer): array
{
    return [
        'content' => [[
            'type' => 'text',
            'text' => is_string($answer) ? $answer : json_encode($answer, JSON_THROW_ON_ERROR),
        ]],
    ];
}

/**
 * @param  array<string, mixed>|string  $answer
 * @return list<Proposal>
 */
function proposalsFrom(array|string $answer, ExtractionKind $kind = ExtractionKind::Contract): array
{
    return (new ReadProposals)->from(providerAnswer($answer), $kind);
}

it('reads a contract’s dates and provisions', function (): void {
    /*
     * The ordinary case, asserted field by field rather than by count — a count
     * passes over a reader that put the label in the value and the page in the
     * confidence, which is exactly the class of mistake a second provider with a
     * different JSON shape would introduce.
     */
    $proposals = proposalsFrom([
        'dates' => [
            [
                'label' => 'Inspection Objection Deadline',
                'value' => '2026-07-25',
                'confidence' => 0.94,
                'page' => 3,
                'quote' => 'Inspection Objection Deadline           July 25, 2026',
                'critical' => true,
            ],
        ],
        'provisions' => [
            [
                'summary' => 'Seller conveys the two garage door openers at Possession.',
                'confidence' => 0.7,
                'page' => 9,
            ],
        ],
    ]);

    expect($proposals)->toHaveCount(2);

    [$date, $provision] = $proposals;

    expect($date->type)->toBe(ExtractedFieldType::KeyDate)
        ->and($date->label)->toBe('Inspection Objection Deadline')
        ->and($date->value)->toBe('2026-07-25')
        ->and($date->confidence)->toBe(0.94)
        ->and($date->sourcePage)->toBe(3)
        ->and($date->sourceSnippet)->toContain('July 25, 2026')
        ->and($date->payload['critical'])->toBeTrue();

    /*
     * A provision has no name of its own — it is a sentence — so the label is
     * the type's own word and the sentence is the value. A reader that put the
     * summary in both would give the review card a heading eleven words long.
     */
    expect($provision->type)->toBe(ExtractedFieldType::Provision)
        ->and($provision->label)->toBe('Provision')
        ->and($provision->value)->toBe('Seller conveys the two garage door openers at Possession.');
});

it('reads an answer the model wrapped in a code fence', function (): void {
    // The prompt asks for a bare object and mostly gets one. A fence is the
    // commonest way it does not, and it is harmless rather than suspicious.
    $fenced = "```json\n".json_encode([
        'dates' => [['label' => 'Closing Date', 'value' => '2026-08-28']],
    ], JSON_THROW_ON_ERROR)."\n```";

    expect(proposalsFrom($fenced))->toHaveCount(1);
});

it('reads an answer with prose in front of it', function (): void {
    // The other tolerated failure. Note what is *not* tolerated: "find some
    // JSON somewhere in this" is how a reader ends up parsing an example out of
    // an apology, which the garbage cases below hold.
    $chatty = "Here are the dates I found in this contract:\n\n".json_encode([
        'dates' => [['label' => 'Closing Date', 'value' => '2026-08-28']],
    ], JSON_THROW_ON_ERROR);

    expect(proposalsFrom($chatty))->toHaveCount(1);
});

it('drops a field type the kind it was asked about cannot produce', function (): void {
    /*
     * `ExtractionKind::proposes()` is the list, and it lives on the enum rather
     * than in the prompt because it is the **response reader** that has to
     * validate against it: a model that returns a task for a contract has
     * misunderstood the request, and accepting it silently would put a row on a
     * screen that has no control for it.
     *
     * Both directions, and each fixture carries a legitimate row beside the
     * rejected one — otherwise the reader would throw for having nothing at all,
     * and the case would pass for the wrong reason.
     */
    $fromContract = proposalsFrom([
        'dates' => [['label' => 'Closing Date', 'value' => '2026-08-28']],
        'tasks' => [['title' => 'Repair the handrail']],
    ], ExtractionKind::Contract);

    expect($fromContract)->toHaveCount(1)
        ->and($fromContract[0]->type)->toBe(ExtractedFieldType::KeyDate);

    $fromInspection = proposalsFrom([
        'dates' => [['label' => 'Closing Date', 'value' => '2026-08-28']],
        'provisions' => [['summary' => 'Seller conveys the openers.']],
        'tasks' => [['title' => 'Repair the handrail']],
    ], ExtractionKind::Inspection);

    expect($fromInspection)->toHaveCount(1)
        ->and($fromInspection[0]->type)->toBe(ExtractedFieldType::Task);
});

it('drops a row with nothing in the field that matters', function (): void {
    /*
     * A date with no value and a task with no title are not rows a human can
     * review — there is nothing on the card to accept. Dropped rather than
     * kept-as-empty, because an empty proposal on S66 reads as *"the model found
     * this and could not tell you what it was"*, which is a sentence nobody can
     * act on.
     *
     * `''` and `'   '` are the same as absent: `string()` trims first, which is
     * what a model padding a table cell produces.
     */
    $dates = proposalsFrom([
        'dates' => [
            ['label' => 'Closing Date'],
            ['label' => 'Record Title Deadline', 'value' => '   '],
            ['value' => '2026-08-02'],
            ['label' => 'Title Resolution Deadline', 'value' => '2026-08-02'],
        ],
    ]);

    expect($dates)->toHaveCount(1)
        ->and($dates[0]->label)->toBe('Title Resolution Deadline');

    $tasks = proposalsFrom([
        'tasks' => [['detail' => 'No title on this one'], ['title' => 'Repair the handrail']],
    ], ExtractionKind::Inspection);

    expect($tasks)->toHaveCount(1);
});

it('clamps a confidence outside the scale instead of dropping the row', function (): void {
    /*
     * `ReadProposals`, in its own words: a confidence outside the range is a
     * model misreading the instruction about the **scale**, which says nothing
     * about whether it read the contract — *"and dropping the row over it would
     * trade a cosmetic problem for the one failure PRD §12.3 has zero tolerance
     * for"*, which is a critical date missed entirely.
     *
     * The column's own CHECK refuses anything outside 0..1, so a reader that
     * passed the number through would fail the insert in a queue worker and lose
     * the whole extraction over a cosmetic field.
     */
    $proposals = proposalsFrom([
        'dates' => [
            ['label' => 'Over', 'value' => '2026-08-28', 'confidence' => 1.4],
            ['label' => 'Under', 'value' => '2026-08-29', 'confidence' => -0.2],
            ['label' => 'Whole', 'value' => '2026-08-30', 'confidence' => 1],
            ['label' => 'Absent', 'value' => '2026-08-31'],
            ['label' => 'Not a number', 'value' => '2026-09-01', 'confidence' => 'high'],
        ],
    ]);

    expect($proposals)->toHaveCount(5)
        ->and($proposals[0]->confidence)->toBe(1.0)
        ->and($proposals[1]->confidence)->toBe(0.0)
        ->and($proposals[2]->confidence)->toBe(1.0)
        ->and($proposals[3]->confidence)->toBeNull()
        ->and($proposals[4]->confidence)->toBeNull();
});

it('normalises a date written some other way', function (string $written, string $iso): void {
    /*
     * S66 renders a date field, and a value it cannot parse renders as an empty
     * one — so `2026-3-4` and `March 28, 2026` are worth turning into the shape
     * the confirm path accepts. What makes this safe rather than the
     * "silently corrected" failure the class warns about is the **round trip**:
     * the parsed day is re-formatted with the same format and compared, so a
     * value the format merely tolerated is refused rather than accepted.
     */
    $proposals = proposalsFrom(['dates' => [['label' => 'Closing Date', 'value' => $written]]]);

    expect($proposals[0]->value)->toBe($iso)
        /*
         * The raw string is kept beside the parsed one, because S66 has to be
         * able to show what the model actually said — the review screen's whole
         * job.
         */
        ->and($proposals[0]->payload['raw_value'])->toBe($written);
})->with([
    'already ISO' => ['2026-03-28', '2026-03-28'],
    'ISO without the padding' => ['2026-3-4', '2026-03-04'],
    'American, padded' => ['03/28/2026', '2026-03-28'],
    'American, unpadded' => ['3/28/2026', '2026-03-28'],
    'the month written out' => ['March 28, 2026', '2026-03-28'],
    'the month abbreviated' => ['Mar 28, 2026', '2026-03-28'],
    'day first' => ['28 March 2026', '2026-03-28'],
]);

it('hands back a value that is not a date exactly as the model wrote it', function (string $written): void {
    /*
     * The specific bug this guards, named in the class: *"`Carbon::parse` is
     * deliberately not reached for here: it reads `"ten days after closing"` as
     * **now**, which would put today's date on the screen with the model's
     * confidence beside it and no sign that anything was guessed."*
     *
     * So the assertion is not merely *"unchanged"* — it is unchanged **and not
     * today**, because a reader that fell back to `now()` would produce a
     * perfectly plausible ISO string that no other assertion here would notice.
     *
     * The overflow case is the same rule arriving from the other end:
     * `createFromFormat` reads `2026-13-45` as a real day in 2027, and a
     * proposal for a date that is nowhere in the contract is worse than a
     * proposal a human can see is wrong.
     */
    $this->freezeAt('2026-08-28 12:00');

    $proposals = proposalsFrom(['dates' => [['label' => 'Inspection objection', 'value' => $written]]]);

    expect($proposals[0]->value)->toBe($written)
        ->and($proposals[0]->value)->not->toBe('2026-08-28');
})->with([
    'an offset the model did not resolve' => 'ten days after closing',
    'an offset in the contract’s own shorthand' => 'MEC + 10 days',
    'a month with no year' => 'Aug 31',
    'a misprint' => 'Marhc 28, 2026',
    'a day that does not exist' => '2026-13-45',
    'nothing date-shaped at all' => 'to be agreed between the parties',
]);

it('refuses an answer it cannot use rather than reporting an empty one', function (string $label, array $raw): void {
    /*
     * *"An empty extraction and a broken one look identical on a screen, and
     * only one of them is worth a person's time to retry."* A contract with no
     * dates in it does not exist, so nothing usable is a failure with a sentence
     * on it rather than a review screen reading *"there was nothing in your
     * contract"* — which somebody would believe.
     *
     * Deliberately **not** retryable, and that is the money rather than the
     * words: PRD §12.3 caps cost per deal at $2, and four attempts at an
     * unparseable answer is four times the price of one.
     */
    expect(fn (): array => (new ReadProposals)->from($raw, ExtractionKind::Contract))
        ->toThrow(ProviderFailed::class);

    try {
        (new ReadProposals)->from($raw, ExtractionKind::Contract);
    } catch (ProviderFailed $failure) {
        expect($failure->reasonCode)->toBe('provider_response_unreadable')
            ->and($failure->isRetryable)->toBeFalse()
            ->and($failure->getMessage())->not->toBe('');
    }
})->with([
    'no content at all' => ['empty', []],
    'content that is not a list of blocks' => ['not-blocks', ['content' => 'sorry']],
    'an apology with no object in it' => ['prose', [
        'content' => [['type' => 'text', 'text' => 'I was unable to read this document.']],
    ]],
    'a text block that is not JSON' => ['broken-json', [
        'content' => [['type' => 'text', 'text' => '{ "dates": [ ']],
    ]],
    'a well-formed answer with nothing in it' => ['empty-answer', [
        'content' => [['type' => 'text', 'text' => '{"dates": [], "provisions": []}']],
    ]],
    'rows the kind cannot produce and nothing else' => ['wrong-kind', [
        'content' => [['type' => 'text', 'text' => '{"tasks": [{"title": "Repair the handrail"}]}']],
    ]],
]);

it('caps an answer that has gone wrong rather than right', function (): void {
    /*
     * Two hundred proposals is already far more than any contract carries, so
     * beyond it the model is repeating itself or the response is not about this
     * document. The cap matters because the rows are written in a loop inside
     * one transaction and then drawn on one screen — an answer with three
     * hundred dates in it is a review screen nobody can work through and a
     * `sort_order` running past what the column holds.
     */
    $rows = [];

    for ($index = 0; $index < 300; $index++) {
        $rows[] = ['label' => 'Deadline '.$index, 'value' => '2026-08-28'];
    }

    expect(proposalsFrom(['dates' => $rows]))->toHaveCount(200);
});

it('keeps a snippet to a quote rather than the document', function (): void {
    /*
     * `source_snippet` is drawn in band 2 of the review card, beside the value —
     * Screen Inventory: the source *"is not a link to check later; it is on
     * screen next to the value."* A model that quoted a whole page would make
     * that card unreadable and the column enormous.
     */
    $proposals = proposalsFrom([
        'dates' => [[
            'label' => 'Closing Date',
            'value' => '2026-08-28',
            'quote' => str_repeat('a', 900),
            'page' => 40_000,
        ]],
    ]);

    expect(mb_strlen((string) $proposals[0]->sourceSnippet))->toBe(600)
        // A page number outside anything a contract has is dropped rather than
        // stored: S66 offers to open the document at it.
        ->and($proposals[0]->sourcePage)->toBeNull();
});

it('keeps only the severities an inspection report may carry', function (): void {
    /*
     * `severity` decides how S67 draws a finding, and `lib/states.ts` throws on
     * a value it does not know. A model returning "urgent" would reach the front
     * end as a state nothing has a colour for, so an unrecognised one is dropped
     * to null here rather than passed through.
     */
    $proposals = proposalsFrom([
        'tasks' => [
            ['title' => 'Handrail', 'severity' => 'safety', 'detail' => 'Loose at the lower bracket.'],
            ['title' => 'Downspout', 'severity' => 'urgent'],
        ],
    ], ExtractionKind::Inspection);

    expect($proposals[0]->payload['severity'])->toBe('safety')
        ->and($proposals[0]->payload['detail'])->toBe('Loose at the lower bracket.')
        ->and($proposals[1]->payload)->not->toHaveKey('severity');
});
