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
use App\Support\Extraction\StartExtraction;
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
        ->and($extraction->error)->toBe('The reading service refused this document.')
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
        ->and($extraction->error)->not->toBeNull();
});
