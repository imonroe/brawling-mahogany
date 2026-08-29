<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use App\Enums\ExtractedFieldType;
use App\Enums\ExtractionKind;
use App\Enums\ExtractionState;
use App\Jobs\RunDocumentExtraction;
use App\Models\Deal;
use App\Models\Document;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Support\Documents\DocumentStorage;
use App\Support\Extraction\ExtractionRefused;
use App\Support\Extraction\PerformExtraction;
use App\Support\Extraction\ProviderFailed;
use App\Support\Extraction\SpendLedger;
use App\Support\Extraction\StartExtraction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Redact, call, write — the whole path a document takes (#113–#115 · PRD §8.4).
 *
 * PRD §8.4 opens with the rule the slice inherits: **never synchronous, never
 * trusted.** Everything below exercises the code that ships, not a stand-in for
 * it: the driver is `anthropic`, the class under the fake is the real
 * `AnthropicProvider`, and the only thing replaced is the socket.
 *
 * ## Why not `NullProvider`
 *
 * It exists and it would be easier, and using it here would prove nothing.
 * `NullProvider`'s own docblock says so: *"a test that exercises a stub proves
 * nothing about the code that ships"* — the `Mail::fake()` trap CLAUDE.md
 * records one subsystem over, where every assertion about a rendered email
 * passed against a view that throws. So the cost calculation, the usage block,
 * the status handling and, above all, **what is in the request body** are all
 * asserted against the real client.
 *
 * `Http::preventStrayRequests()` is on globally (`TestCase::setUp`), which is
 * the other half: a case that forgets its fake fails rather than reaching
 * api.anthropic.com.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    $this->freezeAt('2026-09-10 12:00:00');

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    config([
        'extraction.driver' => 'anthropic',
        'extraction.anthropic.api_key' => 'test-key-not-a-real-one',
        'extraction.anthropic.model' => 'claude-sonnet-5',
        'extraction.caps.team_monthly_micros' => 50_000_000,
        'extraction.caps.platform_monthly_micros' => 500_000_000,
    ]);
});

/**
 * A contract with an identifier in it and a price page worth keeping.
 *
 * Written as a fixture rather than pulled from `tests/Corpus`, because the
 * assertions below name the exact strings on both sides of the redactor — and a
 * fixture whose content can change under a test is a fixture that will one day
 * make the test pass for a reason nobody chose.
 */
function pipelineContract(): string
{
    return "CONTRACT TO BUY AND SELL REAL ESTATE\n\n"
        ."Purchase Price ................................. \$726,500.00\n"
        ."Earnest Money .................................. \$ 15,000.00\n\n"
        ."Inspection Objection Deadline                    July 25, 2026\n"
        ."Closing Date                                     August 28, 2026\n\n"
        ."Wire instructions for the earnest money deposit.\n"
        .'Routing Number: 123456789'."\n";
}

/** A readable document, bytes and all, hanging off the deal. */
function documentOn(Deal $deal, string $text, string $mimeType = 'text/plain', string $name = 'contract.txt'): Document
{
    $path = 'fixtures/'.Str::ulid()->toString();

    Storage::disk(DocumentStorage::DISK)->put($path, $text);

    return Document::factory()->create([
        'team_id' => $deal->team_id,
        'documentable_type' => $deal->getMorphClass(),
        'documentable_id' => $deal->getKey(),
        'category' => DocumentCategory::Other,
        'disk' => DocumentStorage::DISK,
        'path' => $path,
        'original_name' => $name,
        'mime_type' => $mimeType,
        'size_bytes' => strlen($text),
    ]);
}

/**
 * The Messages API, answering with what the prompt asked for.
 *
 * @param  array<string, mixed>  $answer
 * @param  array<string, int>  $usage
 */
function anthropicAnswering(array $answer, array $usage = ['input_tokens' => 9_000, 'output_tokens' => 1_200]): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            /*
             * Not the model that was asked for. #118 re-runs the corpus on
             * every model version change and can only notice one it was told
             * about, so `model_version` records what the API says it served —
             * an alias resolving to a dated build is the ordinary case.
             */
            'model' => 'claude-sonnet-5-20260101',
            'content' => [['type' => 'text', 'text' => json_encode($answer, JSON_THROW_ON_ERROR)]],
            'usage' => $usage,
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function contractAnswer(): array
{
    return [
        'dates' => [
            [
                'label' => 'Inspection Objection Deadline',
                'value' => '2026-07-25',
                'critical' => true,
                'confidence' => 0.94,
                'page' => 3,
                'quote' => 'Inspection Objection Deadline                    July 25, 2026',
            ],
            [
                // Written the way a contract writes it, so the normalisation
                // this pipeline depends on is exercised end to end rather than
                // only in `ReadProposalsTest`.
                'label' => 'Closing Date',
                'value' => 'August 28, 2026',
                'critical' => true,
                'confidence' => 0.99,
                'page' => 5,
                'quote' => 'Closing Date                                     August 28, 2026',
            ],
        ],
        'provisions' => [
            [
                'summary' => 'Earnest money is wired rather than delivered by cheque.',
                'confidence' => 0.8,
                'page' => 6,
            ],
        ],
    ];
}

it('reads a contract into a set of proposals a person can review', function (): void {
    anthropicAnswering(contractAnswer());

    $document = documentOn($this->deal, pipelineContract());

    $extraction = app(StartExtraction::class)
        ->start($document, $this->deal, ExtractionKind::Contract, $this->member)
        ->refresh();

    /*
     * The queue is deliberately not faked (docs/Testing.md §3), so `sync` has
     * already run the whole worker by the time `start()` returns. That is the
     * point: this asserts the pipeline rather than asserting that a job would
     * have been dispatched.
     */
    expect($extraction->state)->toBe(ExtractionState::Complete)
        ->and($extraction->fields()->count())->toBe(3);

    $fields = ExtractedField::query()->orderBy('sort_order')->get();

    expect($fields->pluck('field_type')->all())->toBe([
        ExtractedFieldType::KeyDate,
        ExtractedFieldType::KeyDate,
        ExtractedFieldType::Provision,
    ])
        ->and($fields[0]->label)->toBe('Inspection Objection Deadline')
        ->and($fields[0]->proposed_value)->toBe('2026-07-25')
        ->and($fields[0]->source_page)->toBe(3)
        ->and($fields[0]->source_snippet)->toContain('July 25, 2026')
        ->and($fields[0]->payload['critical'])->toBeTrue()
        // Normalised on the way in, and the words the model wrote kept beside it.
        ->and($fields[1]->proposed_value)->toBe('2026-08-28')
        ->and($fields[1]->payload['raw_value'])->toBe('August 28, 2026')
        ->and($fields[2]->label)->toBe('Provision');

    /*
     * PRD §6.2's invariant, at the end of the pipeline that produces the rows:
     * every one of them is pending, and nothing has reached `key_dates`.
     */
    expect($fields->every(fn (ExtractedField $field): bool => $field->isPending()))->toBeTrue()
        ->and($this->deal->keyDates()->count())->toBe(0);
});

it('sends the provider a redacted document and nothing else', function (): void {
    /*
     * **#114's most important assertion**, and the reason this file drives the
     * real HTTP client: the guarantee is about the bytes on the wire, and only
     * the recorded request can say what was on it.
     *
     * Both halves, because #114 is a pair. *"No document reaches a third-party
     * model without redaction"* — and, in the same issue, *"redaction cannot
     * destroy the dates. A redactor that masks a purchase price or a deadline
     * has broken the feature."* An assertion on only the first would be
     * satisfied by a provider handed an empty string.
     */
    anthropicAnswering(contractAnswer());

    $document = documentOn($this->deal, pipelineContract());

    app(StartExtraction::class)->start($document, $this->deal, ExtractionKind::Contract, $this->member);

    Http::assertSent(function (Request $request): bool {
        /** @var array<string, mixed> $body */
        $body = $request->data();

        $sent = (string) $body['messages'][0]['content'];

        expect($sent)->not->toContain('123456789')
            ->and($sent)->toContain('[redacted: routing number]')
            // …and the document is still worth reading afterwards.
            ->and($sent)->toContain('August 28, 2026')
            ->and($sent)->toContain('July 25, 2026')
            ->and($sent)->toContain('$726,500.00')
            ->and($sent)->toContain('$ 15,000.00');

        // Temperature zero: this is a reading task with a right answer, and a
        // model that phrased the same deadline differently on two runs would
        // make #118's harness unable to tell a prompt change from noise.
        expect($body['temperature'])->toBe(0)
            ->and($body['model'])->toBe('claude-sonnet-5');

        return true;
    });
});

it('records what was sent before it sends it', function (): void {
    /*
     * #114 asks that what was disclosed be *"knowable after the fact"*, and
     * `PerformExtraction` argues the ordering rather than assuming it: *"a
     * record written only on success answers that question for every case except
     * the one where somebody asks it — a call that timed out may still have been
     * received, so the text may still have left."*
     *
     * A timeout is therefore the only fixture that can tell the two orderings
     * apart. Written after the call, `redacted_text` would be null on exactly
     * the row somebody investigates.
     */
    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('timed out')]);

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => documentOn($this->deal, pipelineContract())->getKey(),
    ]);

    try {
        app(PerformExtraction::class)->perform($extraction, $this->team);
    } catch (ProviderFailed) {
        // Retryable, so it is re-thrown for the queue's own attempts. The row
        // is the subject here, not the exception.
    }

    $extraction->refresh();

    expect($extraction->redacted_text)->not->toBeNull()
        ->and($extraction->redacted_text)->toContain('[redacted: routing number]')
        ->and($extraction->redacted_text)->not->toContain('123456789')
        /*
         * Counts, never values — `RedactionReport`'s whole argument, and this
         * column is a JSONB one that no `Redactor::SENSITIVE_KEY_PARTS` covers.
         */
        ->and($extraction->redaction_report['counts'])->toBe(['routing_number' => 1])
        ->and($extraction->redaction_report['total'])->toBe(1);
});

it('records what the call cost and which model made it', function (): void {
    /*
     * PRD §14.3: *"cost and latency per extraction are recorded, because at
     * scale this is the one line item that grows with usage."* Every column
     * asserted with a number rather than `not->toBeNull()`, because a provider
     * that stopped returning a usage block would write noughts and nothing would
     * say so — which is why `AnthropicProvider` computes the cost from the
     * configured rate rather than trusting a figure the API quotes.
     *
     * 9,000 input tokens at $3 per million and 1,200 output at $15 per million
     * is 27,000 + 18,000 micros. Rounded **up**, because the number this feeds
     * is a spend cap and a rule that can report less than was spent is a cap
     * somebody can walk past a fraction at a time.
     */
    anthropicAnswering(contractAnswer());

    $document = documentOn($this->deal, pipelineContract());

    $extraction = app(StartExtraction::class)
        ->start($document, $this->deal, ExtractionKind::Contract, $this->member)
        ->refresh();

    expect($extraction->provider)->toBe('anthropic')
        ->and($extraction->model)->toBe('claude-sonnet-5')
        ->and($extraction->model_version)->toBe('claude-sonnet-5-20260101')
        ->and($extraction->prompt_version)->toBe('contract-2026-08-28')
        ->and($extraction->input_tokens)->toBe(9_000)
        ->and($extraction->output_tokens)->toBe(1_200)
        ->and($extraction->cost_micros)->toBe(45_000)
        ->and($extraction->latency_ms)->toBeInt()
        ->and($extraction->completed_at)->not->toBeNull()
        ->and($extraction->started_at)->not->toBeNull()
        // F10.4: the raw output is kept, because the interesting cases for
        // #118's quality question are the ones the parser dropped.
        ->and($extraction->raw_response['usage']['input_tokens'])->toBe(9_000);
});

it('leaves a row a provider outage can be retried from', function (): void {
    /*
     * #115: *"failure is a state, not an exception the user meets as a 500."*
     * A 5xx is the one failure worth another attempt, so the row goes **back to
     * queued** and the exception is re-thrown for the queue's own four attempts
     * — and it keeps the error in the meantime, because *"a screen saying
     * nothing during a provider outage is the same screen as one saying nothing
     * during a bug."*
     */
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 503)]);

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => documentOn($this->deal, pipelineContract())->getKey(),
    ]);

    expect(fn () => app(PerformExtraction::class)->perform($extraction, $this->team))
        ->toThrow(ProviderFailed::class);

    $extraction->refresh();

    expect($extraction->state)->toBe(ExtractionState::Queued)
        ->and($extraction->error_code)->toBe('provider_unavailable')
        ->and($extraction->error)->toContain('tried again')
        // Nothing was billed and nothing was proposed.
        ->and($extraction->cost_micros)->toBe(0)
        ->and($extraction->fields()->count())->toBe(0);
});

it('stops on a refusal another attempt would reproduce', function (): void {
    /*
     * The other half, and the money is the point: PRD §12.3 caps cost per deal
     * at $2, and four attempts at a 4xx is four times the price of one. So a
     * refusal is terminal, and the row carries a sentence a person can read
     * beside the enumerated code an operator greps — two columns because they
     * have two different readers.
     */
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400)]);

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => documentOn($this->deal, pipelineContract())->getKey(),
    ]);

    app(PerformExtraction::class)->perform($extraction, $this->team);

    $extraction->refresh();

    expect($extraction->state)->toBe(ExtractionState::Failed)
        ->and($extraction->error_code)->toBe('provider_refused_400')
        ->and($extraction->error)->toBe('The extraction service refused this document.')
        ->and($extraction->completed_at)->not->toBeNull();
});

it('refuses to start at all when nobody has said which provider', function (): void {
    /*
     * `config/extraction.php`'s default, and PRD §10's four preconditions —
     * a signed DPA, a no-training commitment, a retention position, and
     * disclosure language in the team's own listing agreement. None is a code
     * change and none can be checked from here, so *"the absence of a decision
     * means nothing leaves"*.
     *
     * The assertion that matters is the absent row rather than the exception: a
     * refusal that still queued a job would send somebody's contract to
     * whatever the queue eventually resolved. And no fake is registered, so
     * `Http::preventStrayRequests()` would fail this test if anything reached
     * for a socket.
     */
    config(['extraction.driver' => 'null']);

    $document = documentOn($this->deal, pipelineContract());

    try {
        app(StartExtraction::class)->start($document, $this->deal, ExtractionKind::Contract, $this->member);

        $this->fail('Extraction should have been refused.');
    } catch (ExtractionRefused $refusal) {
        expect($refusal->reasonCode)->toBe('provider_not_configured')
            ->and($refusal->getMessage())->toContain('not switched on');
    }

    expect(Extraction::query()->count())->toBe(0);
});

it('lets only one worker read a document', function (): void {
    /*
     * `message_key`'s pattern, one table over: the claim is a conditional
     * `UPDATE … WHERE state = 'queued'`, not a read followed by a write. Two
     * workers that both read `queued` both proceed, and the cost of that here is
     * a duplicate provider call — real money — and a review screen with every
     * proposal on it twice.
     *
     * `ShouldBeUnique` narrows the window and does not close it, which is why
     * this asserts the claim rather than the interface. The second worker stands
     * down **silently**: it did not lose a race worth complaining about, it
     * arrived second, which is the ordinary case.
     */
    anthropicAnswering(contractAnswer());

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => documentOn($this->deal, pipelineContract())->getKey(),
    ]);

    /*
     * The second worker's copy is read **before** the first one runs, which is
     * the whole shape of the race: both hold a row that said `queued` when they
     * looked at it. Re-reading afterwards would hand the second worker a
     * `complete` row and prove only that Eloquent noticed.
     */
    $worker = app(PerformExtraction::class);
    $secondWorkersCopy = Extraction::query()->whereKey($extraction->getKey())->sole();

    $worker->perform($extraction, $this->team);
    $worker->perform($secondWorkersCopy, $this->team);

    Http::assertSentCount(1);

    expect(ExtractedField::query()->count())->toBe(3)
        ->and($extraction->refresh()->state)->toBe(ExtractionState::Complete);
});

it('says so before it spends anything on a file with no words in it', function (): void {
    /*
     * PRD assumption A10 is explicit that reading a scan without a text layer is
     * unverified, and this product has no OCR. `StartExtraction` reads the words
     * *before* writing a row for exactly that reason: finding out here costs one
     * read of a file already on disk, and finding out in the worker costs a
     * provider call and leaves a failed row that reads like the model's fault.
     *
     * The refusal is a response rather than an error (#99's rule, one module
     * over): it says what to do instead, which is the part that makes it
     * acceptable rather than infuriating.
     */
    $photograph = documentOn($this->deal, "\xff\xd8\xff\xe0 not text at all", 'image/jpeg', 'contract-photo.jpg');

    try {
        app(StartExtraction::class)->start($photograph, $this->deal, ExtractionKind::Contract, $this->member);

        $this->fail('A file with no readable text should have been refused.');
    } catch (ExtractionRefused $refusal) {
        expect($refusal->reasonCode)->toBe('document_has_no_text')
            ->and($refusal->getMessage())->toContain('photograph')
            ->and($refusal->getMessage())->toContain('by hand');
    }

    /*
     * No row at all — not even a `failed` one. A refusal that wrote a row would
     * put a permanent failure on S65 for a file the product was never going to
     * be able to read, which is a different sentence from the one above.
     */
    expect(Extraction::query()->count())->toBe(0);
});

it('will not start a second reading of a document already being read', function (): void {
    /*
     * Not an optimisation: *"two workers reading the same contract produce two
     * review screens proposing the same eleven dates, and somebody confirms
     * both."* Asserted against a row this test leaves `processing`, which is the
     * state the guard exists for — a `complete` row is allowed a second reading,
     * because that is what a retry after a bad answer is.
     */
    $document = documentOn($this->deal, pipelineContract());

    Extraction::factory()->processing()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    try {
        app(StartExtraction::class)->start($document, $this->deal, ExtractionKind::Contract, $this->member);

        $this->fail('A second reading should have been refused.');
    } catch (ExtractionRefused $refusal) {
        expect($refusal->reasonCode)->toBe('extraction_already_running');
    }

    expect(Extraction::query()->count())->toBe(1);
});

it('tells the team once the queue has given up, and not on every attempt', function (): void {
    /*
     * `PerformExtraction::perform()` says this in as many words, and made
     * `fail()` public for it: on a **retryable** failure it writes the error and
     * re-throws without announcing anything, because *"telling the team on every
     * attempt would send four emails about one provider blip, which is how a
     * notification type earns a filter rule and stops being read"* — and the
     * team is told once, *"by `RunDocumentExtraction::failed()`, when the
     * attempts are actually exhausted."*
     *
     * That handler is the only thing standing between a provider outage and
     * silence: the row goes back to `queued` on every attempt, so after the
     * fourth there is nothing that says the reading never happened. A promise
     * made in a docblock is not a promise the code keeps (CLAUDE.md, Slice 4's
     * first finding), so it is asserted here rather than read.
     */
    expect(method_exists(RunDocumentExtraction::class, 'failed'))->toBeTrue(
        'PerformExtraction::fail() is documented as being called by RunDocumentExtraction::failed(), '
        .'which does not exist — so a provider outage that exhausts every attempt tells nobody.',
    );

    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 503)]);

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => documentOn($this->deal, pipelineContract())->getKey(),
    ]);

    $job = (new RunDocumentExtraction($extraction->getKey()))->forTeam($this->team->getKey());

    expect(fn () => $job->handle(app(PerformExtraction::class)))->toThrow(ProviderFailed::class);

    expect($extraction->refresh()->state)->toBe(ExtractionState::Queued);

    // The queue has now given up. Whatever the handler is called, this is the
    // moment the row stops being recoverable and somebody has to be told.
    $job->failed(ProviderFailed::unavailable());

    expect($extraction->refresh()->state)->toBe(ExtractionState::Failed)
        /*
         * **The sentence, not merely a sentence.** `not->toBeNull()` was the
         * whole assertion for a round, and it is true of the version this fix
         * replaced — which preferred the row's own `error` and so announced
         * `ProviderFailed::unavailable()`'s *"This will be tried again."* at
         * the exact moment there would be no further attempt. A person reading
         * that waits for a reading that is never coming.
         *
         * Both halves, because either alone is satisfied by a message that
         * says both things.
         */
        ->and($extraction->error)->toContain('after several attempts')
        ->and($extraction->error)->not->toContain('tried again');
});

it('keeps what the call cost even when the answer is one it cannot use', function (): void {
    /*
     * The failure `raw_response` exists for, and the one the ordering fix was
     * about: `ReadProposals::from()` throws `unreadableResponse()` on an
     * envelope it cannot decode **and** on an answer that yields no proposals.
     * That throw is non-retryable, so `perform()` falls to `fail()`, which
     * writes state and error and nothing else.
     *
     * Written before the fix, the row recorded `cost_micros = 0`,
     * `raw_response = null`, `provider = null`, `model = null` — over a call
     * that happened and was charged. Three things follow, and each contradicts
     * something argued elsewhere in this slice: the spend cap under-counts real
     * spend (`AnthropicProvider` refuses a 200 with no usage block for exactly
     * that reason); `raw_response` is null for the one case the migration says
     * it exists for — *"the interesting cases are the ones the parser
     * dropped"*; and `ExtractionHistory::versions()` filters `whereNotNull
     * ('model')`, so the attempt is absent from the quality breakdown too.
     *
     * The fix is an ordering — the provider facts are written the moment
     * `extract()` returns, before the reader is called — and **restoring the
     * old ordering leaves the suite green without this case**, which is why
     * round 1 found it by reading rather than by running.
     */
    anthropicAnswering(['dates' => [], 'tasks' => [], 'provisions' => []]);

    $document = documentOn($this->deal, pipelineContract());

    $extraction = app(StartExtraction::class)
        ->start($document, $this->deal, ExtractionKind::Contract, $this->member)
        ->refresh();

    expect($extraction->state)->toBe(ExtractionState::Failed)
        ->and($extraction->cost_micros)->toBe(45_000)
        ->and($extraction->provider)->toBe('anthropic')
        ->and($extraction->model)->toBe('claude-sonnet-5')
        ->and($extraction->model_version)->toBe('claude-sonnet-5-20260101')
        ->and($extraction->raw_response)->not->toBeNull();

    /*
     * And the number the cap is computed from actually moves, which is the
     * consequence rather than the column. A ledger that could not see this
     * would let a model reliably answering in prose burn the month's budget
     * while reporting nothing spent.
     */
    expect(app(SpendLedger::class)->teamSpentThisMonth($this->team))->toBe(45_000);
});

it('ends the row on a failure it has no name for, rather than leaving it claimed', function (): void {
    /*
     * `perform()` caught `ProviderFailed` and `RedactionFailed` and nothing
     * else, so any other exception left the row `processing` with the claim
     * taken and no outcome written — and `extractions_one_running` makes that
     * state **absorbing**: the document can never be extracted again, at the
     * database as well as at `StartExtraction`'s pre-check.
     *
     * The case that reaches it in production is ordinary rather than exotic: a
     * document soft-deleted while its extraction sat in the queue, where
     * `run()` hands `$extraction->document` straight to
     * `DocumentStorage::contents()` and gets a `TypeError` on null.
     *
     * Both halves are asserted, because they are two different promises. The
     * row is **final**, so the document is free — and the exception is still
     * **re-thrown**, so the failure reaches the queue, Horizon and the log with
     * its stack intact. Swallowing it would trade a stranded row for a silent
     * one.
     */
    $document = documentOn($this->deal, pipelineContract());

    $extraction = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    $document->delete();

    $job = (new RunDocumentExtraction($extraction->getKey()))->forTeam($this->team->getKey());

    /*
     * A `try`/`catch` rather than `toThrow(Throwable::class)`, and the reason
     * is a trap worth recording: Pest's `toThrow()` branches on
     * **`class_exists()`**, and `Throwable` is an *interface*. So the class
     * string is not recognised as a class and falls through to the
     * message-matching branch — the assertion silently becomes *"does the
     * exception message contain the word Throwable"*, which the `TypeError`
     * here does not.
     *
     * That fails loudly, which is the lucky direction. The unlucky one is an
     * exception whose message happens to contain the interface's name, where
     * the same mistake passes and asserts nothing about the type at all.
     *
     * Naming `TypeError` instead would pass, and would pin this case to the
     * one exception that reaches it today — where what is being asserted is
     * that **anything** unrecognised ends the row.
     */
    $threw = false;

    try {
        $job->handle(app(PerformExtraction::class));
    } catch (Throwable) {
        $threw = true;
    }

    /*
     * The failure is never lost, on either path — and the two paths are not the
     * same, which is what this assertion is really pinning.
     *
     * Under a worker, `$this->fail($e)` records the exception, ends the
     * attempts, and returning is correct. Invoked **directly**, as here,
     * `InteractsWithQueue::fail()` is `$this->job?->fail($e)` with no job, so
     * it is a no-op — and returning would swallow the failure completely: no
     * `failed_jobs` row, no log line, nothing thrown. The job re-throws in that
     * case, which is why this still holds after the retry fix.
     */
    expect($threw)->toBeTrue(
        'A direct invocation has no queue job to mark, so the exception has to '
        .'escape or the failure is lost entirely.',
    );

    $extraction->refresh();

    /*
     * And a second attempt does **not** run.
     *
     * Round 3 asked the shape question and it is a real one: left to the
     * ordinary retry, attempts 2, 3 and 4 find a row that is no longer
     * `queued`, `claim()` no-ops, each returns normally — so the queue records
     * a *success* for work that failed, `failed()` never fires, and three
     * worker slots are spent finding out nothing. `handle()` calls `fail()`
     * when the row has ended, so the queue's record agrees with the row's.
     *
     * Asserted by running the job again and proving it changes nothing, since
     * the `fail()` call itself is the queue's business rather than the row's.
     */
    $before = $extraction->only(['state', 'error', 'error_code', 'completed_at']);

    $threwAgain = false;

    try {
        $job->handle(app(PerformExtraction::class));
    } catch (Throwable) {
        $threwAgain = true;
    }

    expect($threwAgain)->toBeFalse('a claimed, ended row must not be picked up again')
        ->and($extraction->fresh()?->only(['state', 'error', 'error_code', 'completed_at']))->toBe($before);

    expect($extraction->state)->toBe(ExtractionState::Failed)
        ->and($extraction->state->isFinal())->toBeTrue()
        ->and($extraction->error_code)->toBe('extraction_errored')
        /*
         * And the sentence names no cause. An exception's message is written
         * for a log and can carry a library's own words, a path, or a fragment
         * of a document — none of which belongs on a screen a team reads.
         */
        ->and($extraction->error)->toContain('extract it again');
});

it('holds the one-at-a-time rule at the database, not only at the check above it', function (): void {
    /*
     * `StartExtraction`'s `exists()` pre-check is a read-then-write: two tabs
     * or a double press on a slow connection both see no sibling and both
     * write. `extractions_one_running` is what actually decides, and the case
     * above it exercises only the pre-check — so dropping the index from the
     * migration turned nothing red.
     *
     * This inserts straight past the pre-check, which is the only way to reach
     * the constraint from a test, and it is also the shape the race takes in
     * production: two writes that were both told there was nothing running.
     */
    $document = documentOn($this->deal, pipelineContract());

    Extraction::factory()->processing()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    expect(fn () => Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lets a finished attempt be read again, which is what a retry is', function (): void {
    /*
     * The other half, and it is the half the index is *partial* for. A
     * `complete` row must not block a second reading: that is a retry after a
     * bad answer, and it is what `ExtractionHistory::versions()`' whole premise
     * — *"has a version change made it worse"* — needs to be possible at all.
     *
     * Asserted at the database rather than through `StartExtraction`, because
     * the constraint is what would refuse it and the pre-check already agrees.
     */
    $document = documentOn($this->deal, pipelineContract());

    Extraction::factory()->complete()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    Extraction::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    $second = Extraction::factory()->queued()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $document->getKey(),
    ]);

    expect($second->exists)->toBeTrue()
        ->and(Extraction::query()->where('document_id', $document->getKey())->count())->toBe(3);
});
