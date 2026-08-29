<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use App\Enums\ExtractionKind;
use App\Models\Deal;
use App\Models\Document;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\KeyDate;
use App\Models\Team;
use App\Support\Documents\DocumentStorage;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * The tenant boundary around S65, S66 and S67 (#115–#117 · ADR 0002).
 *
 * `extractions` and `extracted_fields` sit inside all five layers, so most of
 * this confirms rather than enforces. Three things here are genuine enforcement,
 * and each is a place the layers do not reach:
 *
 * - **The nesting.** Two extractions in one team both pass the global scope and
 *   both satisfy `ExtractionPolicy`; only `Route::scopeBindings()` answers
 *   *whose deal*. The same one level down for `{field}` through `{extraction}`.
 * - **`documentId` in a request body.** There is no document in the URL to
 *   scope, so the only thing between the column and another team's contract is
 *   the rule in `StartExtractionRequest`. That rule is written as a closure over
 *   the model's own query rather than `Rule::exists`, because `exists` builds a
 *   raw query the global scope never sees.
 * - **What confirming *creates*.** A leak here is not a read: it is another
 *   team's contract date landing on this team's contingency calendar, with a
 *   colleague's name recorded as having confirmed it.
 *
 * Every refusal is paired with the same actor succeeding on their own row. A 404
 * proved without that control passes whether or not the check exists.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();

    config([
        'extraction.driver' => 'anthropic',
        'extraction.anthropic.api_key' => 'test-key-not-a-real-one',
        'extraction.anthropic.model' => 'claude-sonnet-5',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-5-20260101',
            'content' => [['type' => 'text', 'text' => json_encode([
                'dates' => [['label' => 'Closing Date', 'value' => '2026-08-28', 'confidence' => 0.9]],
            ], JSON_THROW_ON_ERROR)]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ]),
    ]);
});

/** A readable contract hanging off the given deal, bytes on the fake disk. */
function readableDocumentFor(Deal $deal): Document
{
    $path = 'fixtures/'.Str::ulid()->toString();

    Storage::disk(DocumentStorage::DISK)->put($path, "CONTRACT\n\nClosing Date   August 28, 2026\n");

    return Document::factory()->create([
        'team_id' => $deal->team_id,
        'documentable_type' => $deal->getMorphClass(),
        'documentable_id' => $deal->getKey(),
        'category' => DocumentCategory::Other,
        'disk' => DocumentStorage::DISK,
        'path' => $path,
        'original_name' => 'contract.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 40,
    ]);
}

/**
 * A deal with a document, a complete extraction, and one pending proposal.
 *
 * @return array{0: Deal, 1: Document, 2: Extraction, 3: ExtractedField}
 */
function extractionFixtureFor(Team $team): array
{
    return app(TeamContext::class)->runFor($team, function () use ($team): array {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        $document = readableDocumentFor($deal);

        $extraction = Extraction::factory()->contract()->complete()->create([
            'team_id' => $team->getKey(),
            'deal_id' => $deal->getKey(),
            'document_id' => $document->getKey(),
        ]);

        $field = ExtractedField::factory()->keyDate('Closing Date', '2026-08-28')->create([
            'team_id' => $team->getKey(),
            'extraction_id' => $extraction->getKey(),
        ]);

        return [$deal, $document, $extraction, $field];
    });
}

it('404s another team’s extraction on every route', function (): void {
    [$foreignDeal, $foreignDocument, $foreignExtraction, $foreignField] = extractionFixtureFor($this->teamB);
    [$ownDeal, $ownDocument, $ownExtraction, $ownField] = extractionFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own deal answers all of this.
    $this->get("/deals/{$ownDeal->getKey()}/extractions/{$ownExtraction->getKey()}")->assertOk();
    $this->post("/deals/{$ownDeal->getKey()}/extractions", [
        'documentId' => $ownDocument->getKey(),
        'kind' => ExtractionKind::Contract->value,
    ])->assertRedirect();
    $this->post("/deals/{$ownDeal->getKey()}/extractions/{$ownExtraction->getKey()}/fields/{$ownField->getKey()}")
        ->assertRedirect();

    /*
     * 404, not 403. ADR 0002 layer 3: a 403 confirms the record exists, which is
     * a disclosure in itself — and here what it would confirm is that another
     * agency is reading a contract on a deal with that id.
     */
    $this->post("/deals/{$foreignDeal->getKey()}/extractions", [
        'documentId' => $foreignDocument->getKey(),
        'kind' => ExtractionKind::Contract->value,
    ])->assertNotFound();

    $this->get("/deals/{$foreignDeal->getKey()}/extractions/{$foreignExtraction->getKey()}")->assertNotFound();

    $this->post("/deals/{$foreignDeal->getKey()}/extractions/{$foreignExtraction->getKey()}/fields", [
        'ids' => [$foreignField->getKey()],
    ])->assertNotFound();

    $this->post(
        "/deals/{$foreignDeal->getKey()}/extractions/{$foreignExtraction->getKey()}/fields/{$foreignField->getKey()}",
    )->assertNotFound();

    $this->delete(
        "/deals/{$foreignDeal->getKey()}/extractions/{$foreignExtraction->getKey()}/fields/{$foreignField->getKey()}",
    )->assertNotFound();

    /*
     * Untouched by any of it — and the row is read **unscoped**, because a
     * scoped read from this team would answer "nothing here" whether the
     * refusals held or not.
     */
    $foreignField = ExtractedField::withoutTeamScope()->whereKey($foreignField->getKey())->sole();

    expect($foreignField->isPending())->toBeTrue()
        ->and($foreignField->reviewed_by)->toBeNull()
        ->and(KeyDate::withoutTeamScope()->where('deal_id', $foreignDeal->getKey())->count())->toBe(0);
});

it('404s an extraction reached through the wrong deal in the same team', function (): void {
    /*
     * Both rows are in the acting team, so the global scope has no objection and
     * `ExtractionPolicy` is asked about an extraction that really does belong to
     * the team. Only the nesting refuses this — and the consequence is not an
     * information leak but a **write to the wrong deal**: `ConfirmExtractedField`
     * takes the deal off `$field->extraction->deal`, so a confirmation reached
     * through the wrong URL would still land its date correctly, while the screen
     * that showed it belonged to another transaction entirely.
     */
    [$dealOne, , $extractionOne, $fieldOne] = extractionFixtureFor($this->teamA);
    [$dealTwo] = extractionFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: through its own deal, the same extraction answers.
    $this->get("/deals/{$dealOne->getKey()}/extractions/{$extractionOne->getKey()}")->assertOk();

    $this->get("/deals/{$dealTwo->getKey()}/extractions/{$extractionOne->getKey()}")->assertNotFound();

    $this->post("/deals/{$dealTwo->getKey()}/extractions/{$extractionOne->getKey()}/fields/{$fieldOne->getKey()}")
        ->assertNotFound();

    $this->delete("/deals/{$dealTwo->getKey()}/extractions/{$extractionOne->getKey()}/fields/{$fieldOne->getKey()}")
        ->assertNotFound();

    expect($fieldOne->refresh()->isPending())->toBeTrue();
});

it('404s a proposal reached through the wrong extraction', function (): void {
    /*
     * One level further down, and the layers reach it even less: `{field}` is
     * resolved through `Extraction::fields()`, so two extractions on **one deal**
     * — a contract and the inspection report that followed it, which is the
     * ordinary shape — are the case a `where team_id` cannot separate.
     */
    [$deal, $document, $contract, $contractField] = extractionFixtureFor($this->teamA);

    $inspection = app(TeamContext::class)->runFor($this->teamA, fn (): Extraction => Extraction::factory()
        ->inspection()
        ->complete()
        ->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $deal->getKey(),
            'document_id' => $document->getKey(),
        ]));

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: through its own extraction, the same field answers.
    $this->delete("/deals/{$deal->getKey()}/extractions/{$contract->getKey()}/fields/{$contractField->getKey()}")
        ->assertRedirect();

    $this->post("/deals/{$deal->getKey()}/extractions/{$inspection->getKey()}/fields/{$contractField->getKey()}")
        ->assertNotFound();
});

it('refuses a document that is not on this deal, from either direction', function (): void {
    /*
     * The vector with no id in the URL. `StartExtractionRequest` asks two
     * questions of `documentId` and both matter: the global scope answers *whose
     * team*, and the `documentable` predicate answers *whose deal* — a document
     * on the team's own other deal passes the tenancy check and would put one
     * transaction's contract on another's calendar, with the model told it was
     * reading this one.
     */
    [, $foreignDocument] = extractionFixtureFor($this->teamB);
    [$ownDeal, $ownDocument] = extractionFixtureFor($this->teamA);
    [, $siblingDocument] = extractionFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own deal's own document is accepted.
    $this->post("/deals/{$ownDeal->getKey()}/extractions", [
        'documentId' => $ownDocument->getKey(),
        'kind' => ExtractionKind::Contract->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$foreignDocument, $siblingDocument] as $document) {
        $this->post("/deals/{$ownDeal->getKey()}/extractions", [
            'documentId' => $document->getKey(),
            'kind' => ExtractionKind::Contract->value,
        ])->assertSessionHasErrors('documentId');
    }

    /*
     * Two rows on their deal — the fixture's and the control's — and neither
     * refusal wrote a third. The pairing is asserted rather than the count
     * alone, because a row naming this deal and somebody else's document is the
     * shape the defect would take, and a worker picks the row up either way.
     */
    expect(Extraction::query()->where('deal_id', $ownDeal->getKey())->count())->toBe(2)
        ->and(Extraction::withoutTeamScope()
            ->where('deal_id', $ownDeal->getKey())
            ->where('document_id', $foreignDocument->getKey())
            ->exists())->toBeFalse()
        ->and(Extraction::query()
            ->where('deal_id', $ownDeal->getKey())
            ->where('document_id', $siblingDocument->getKey())
            ->exists())->toBeFalse();
});

it('does not let one team’s confirmation write onto another team’s deal', function (): void {
    /*
     * The highest-consequence case in this file, and it is about the **write**
     * rather than the read. A confirmation creates a `key_dates` row with a
     * colleague's name and timestamp on it; landing one on another agency's deal
     * would put a date on their contingency calendar that nobody in that team has
     * ever seen, attributed to somebody who is not in it.
     */
    [$foreignDeal, , $foreignExtraction, $foreignField] = extractionFixtureFor($this->teamB);
    [$ownDeal, , $ownExtraction, $ownField] = extractionFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: on their own extraction, confirming writes the date.
    $this->post("/deals/{$ownDeal->getKey()}/extractions/{$ownExtraction->getKey()}/fields/{$ownField->getKey()}")
        ->assertRedirect();

    expect(KeyDate::query()->where('deal_id', $ownDeal->getKey())->count())->toBe(1);

    /*
     * Every way a crafted request could pair them: the foreign field under its
     * own deal and extraction, and the foreign field smuggled under the actor's
     * own URL — the second is the one no tenancy scope refuses on its own,
     * because the deal and the extraction in the path are genuinely theirs.
     */
    $this->post(
        "/deals/{$foreignDeal->getKey()}/extractions/{$foreignExtraction->getKey()}/fields/{$foreignField->getKey()}",
    )->assertNotFound();

    $this->post("/deals/{$ownDeal->getKey()}/extractions/{$ownExtraction->getKey()}/fields/{$foreignField->getKey()}")
        ->assertNotFound();

    /*
     * And the bulk endpoint, which takes its ids from a **request body** and so
     * has no binding to refuse them. It answers 302 rather than 404 because it
     * filters the ids to the named extraction's own rows before doing anything —
     * so the assertion is what it *wrote*, which has to be nothing.
     *
     * The extraction has to be an inspection one for the endpoint to be
     * reachable at all (`AcceptExtractedFieldsRequest` refuses a contract
     * outright), which is why this is not simply pointed at `$ownExtraction`.
     */
    $ownInspection = app(TeamContext::class)->runFor($this->teamA, fn (): Extraction => Extraction::factory()
        ->inspection()
        ->complete()
        ->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $ownDeal->getKey(),
            'document_id' => $ownExtraction->document_id,
        ]));

    $this->post("/deals/{$ownDeal->getKey()}/extractions/{$ownInspection->getKey()}/fields", [
        'ids' => [$foreignField->getKey()],
    ])->assertRedirect();

    $foreignField = ExtractedField::withoutTeamScope()->whereKey($foreignField->getKey())->sole();

    expect($foreignField->isPending())->toBeTrue()
        ->and($foreignField->reviewed_by)->toBeNull()
        ->and(KeyDate::withoutTeamScope()->where('deal_id', $foreignDeal->getKey())->count())->toBe(0);
});

it('never shows one team another team’s spend, even when the platform is what stopped them', function (): void {
    /*
     * Round 3's B4, and it is a leak of a **number** rather than of a row —
     * which ADR 0002 says is the kind that goes unnoticed.
     *
     * `SpendLedger::decide()` answers the platform question first and returns
     * platform figures in the decision, correctly: the refusal is about the
     * platform. `DealDocumentController::extractProps()` then shipped those
     * figures to S65 without asking which ceiling they described, and
     * `ExtractDocumentDialog` drew them under a comment reading *"Where this
     * team stands against its own cap."* So from the moment the platform
     * ceiling was reached, every team's Extract dialog showed the whole
     * installation's total as its own.
     *
     * The state is the incident this subsystem exists for. Team B holds all the
     * spend; team A has spent nothing and is asked what it has spent.
     *
     * S68 was never affected, because `ExtractionHistory::spend()` reads
     * `capFor()` and `teamSpentThisMonth()` directly — which is what made two
     * screens disagree about one team on one day.
     */
    $this->travelTo('2026-09-10 12:00:00');

    config([
        'extraction.caps.platform_monthly_micros' => 10_000_000,
        'extraction.caps.team_monthly_micros' => 50_000_000,
    ]);

    app(TeamContext::class)->runFor($this->teamB, function (): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamB->getKey()]);

        Extraction::factory()->complete()->costing(10_000_000)->create([
            'team_id' => $this->teamB->getKey(),
            'deal_id' => $deal->getKey(),
        ]);
    });

    [$deal] = extractionFixtureFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get("/deals/{$deal->getKey()}/documents")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $extract = $page->toArray()['props']['extract'];

            /*
             * **The team's own figure, asserted as a value.**
             *
             * This was `not->toContain('10.00')` for a round, and round 4 was
             * right that it could not fail on the one datum the case is named
             * for. `extractionFixtureFor()` builds its extraction with
             * `complete()`, which costs 45,000 micros — so under the defect
             * `used` is the platform total of 10,045,000, rendered `$10.05`,
             * and a substring test for `10.00` is satisfied by it.
             *
             * The case would still have gone red on `cap` and `percent`. That
             * is not much comfort: those two are a config value and a
             * derivative of it, and `used` is the **only one of the three that
             * is another tenant's data at all**. A later change that composed
             * the other two from the ledger and left `used` on the decision
             * would have met a green suite and a leaked spend figure.
             *
             * So it asserts the value. `$0.05` is team A's own fixture
             * extraction and nothing else, which is what makes it checkable by
             * hand rather than merely different.
             */
            expect($extract['spend']['used'])->toBe('$0.05')
                ->and($extract['spend']['cap'])->toBe('$50.00')
                ->and($extract['spend']['percent'])->toBeLessThan(100);

            /*
             * And the refusal still arrives — this is not a test that the cap
             * stopped working. The platform ceiling is reached, so team A
             * cannot extract; it simply is not told the platform's numbers.
             */
            expect($extract['allowed'])->toBeFalse()
                ->and($extract['unavailableReason'])->toContain('paused across this installation');
        });
});
