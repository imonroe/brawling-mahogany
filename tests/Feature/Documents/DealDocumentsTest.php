<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Enums\WorkflowState;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Gate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Stage;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Policies\DocumentPolicy;
use App\Support\Documents\DocumentStorage;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\DescribeBlockers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * S21 — the deal Documents tab (issues #98, #99, #100).
 *
 * The guardrails have their own suite; these are about the screen reaching
 * them, which is CLAUDE.md's *"a row nothing can reach is a rule nobody is
 * following"* applied to the upload path itself. A scanner with no route in
 * front of it protects nothing.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    /*
     * A member, not an owner. `teamWithOwner()` is the wrong default for a
     * feature test — PRD §9 makes 2FA mandatory for owners, so every request
     * redirects to enrolment rather than reaching the screen.
     */
    [$this->team, $this->owner] = $this->teamWithMember();
    $this->actingAsPerson($this->owner, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

/**
 * A real temp file, so `getMimeType()` runs `finfo` over genuine bytes.
 * `Illuminate\Http\Testing\File` answers from the *filename*, so an upload
 * test written with `fake()` never reaches a bytes-decided allowlist — the
 * same trap `tests/Feature/Mail/` documents one layer along.
 */
function dealUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * The smallest thing `finfo` will call `image/png`.
 */
function onePixelPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

it('renders the tab with its documents', function (): void {
    $storage = app(DocumentStorage::class);
    $storage->store(
        $this->deal,
        dealUpload('inspection.txt', 'Roof looks sound.'),
        $this->owner,
        DocumentCategory::InspectionReport,
    );

    $this->get("/deals/{$this->deal->getKey()}/documents")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Documents')
            ->has('documents', 1)
            ->where('documents.0.name', 'inspection.txt')
            ->where('documents.0.category', DocumentCategory::InspectionReport->value),
        );
});

it('stores a document the scan does not refuse', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/documents", [
        'document' => dealUpload('disclosure.txt', 'The seller discloses a prior roof repair.'),
        'category' => DocumentCategory::Disclosure->value,
        'visibility' => DocumentVisibility::Internal->value,
        'caption' => 'Signed copy on file elsewhere',
    ])->assertRedirect();

    $document = Document::query()->sole();

    expect($document->documentable_id)->toBe($this->deal->getKey())
        ->and($document->category)->toBe(DocumentCategory::Disclosure)
        ->and($document->caption)->toBe('Signed copy on file elsewhere')
        ->and($document->team_id)->toBe($this->team->getKey());
});

it('refuses a bank statement with somewhere to send it instead', function (): void {
    /*
     * The whole reason this screen can exist. #63 closed Slice 2's residual
     * window by restricting context — images only, against a property only —
     * and a general upload path is only safe because the bytes are read.
     *
     * The refusal carries three things, and the third is the one #99 calls
     * *"what makes this acceptable rather than infuriating"*.
     */
    $this->post("/deals/{$this->deal->getKey()}/documents", [
        'document' => dealUpload('statement.txt', "Account Number: 1234567890\nRouting Number: 021000021\nBeginning Balance: 4,201.55"),
        'category' => DocumentCategory::Other->value,
        'visibility' => DocumentVisibility::Internal->value,
    ])->assertRedirect()->assertSessionHas('refusal');

    // Nothing refused is ever stored: the scan runs before the transaction.
    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);

    $refusal = session('refusal');

    expect($refusal)->toHaveKeys(['category', 'reason', 'alternative'])
        // And it never names the file, because a filename is often the most
        // descriptive thing about a document (PRD §9).
        ->and(json_encode($refusal, JSON_THROW_ON_ERROR))->not->toContain('statement.txt');
});

it('defaults a document to internal, and says so in three places at once', function (): void {
    /*
     * F6.3's default has to hold wherever a document is made — the enum, the
     * column, and the storage service. A default that lives only in the form
     * is a default the next caller does not have.
     */
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('notes.txt', 'Nothing sensitive here.'),
        $this->owner,
        DocumentCategory::Other,
    );

    expect($document->visibility)->toBe(DocumentVisibility::Internal)
        ->and($document->fresh()->visibility)->toBe(DocumentVisibility::Internal);
});

it('sweeps a deal’s documents and their bytes when the deal goes', function (): void {
    /*
     * `documents.documentable_id` is polymorphic, so **no foreign key reaches
     * it** and nothing cascades. `HasDocuments` sweeps on the parent's own
     * `deleting` hook — and the bytes live on a disk the row-level sweep does
     * not touch, which is why `remove()` and not just a delete.
     */
    app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('report.txt', 'Roof looks sound.'),
        $this->owner,
        DocumentCategory::InspectionReport,
    );

    expect(Storage::disk(DocumentStorage::DISK)->allFiles())->toHaveCount(1);

    $this->deal->forceDelete();

    /*
     * **The bytes go now; the row keeps PRD §9's thirty days.** That split is
     * the design rather than an oversight — `records:purge` finds rows by
     * `deleted_at` and hard-deletes them later, while a soft-deleted row whose
     * file is still readable by anything holding the key is a deletion that
     * did not delete, and the key is the only thing an object store checks.
     *
     * So the row is invisible to an ordinary query and the file is gone.
     */
    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([])
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('counts documents on the deal header, so the tab is not a guess', function (): void {
    app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('one.txt', 'Nothing sensitive.'),
        $this->owner,
        DocumentCategory::Other,
    );

    $this->get("/deals/{$this->deal->getKey()}/documents")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('dealHeader.counts.documents', 1));
});

it('will not let one team reach another team’s document', function (): void {
    $other = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $document = app(DocumentStorage::class)->store(
        $other,
        dealUpload('theirs.txt', 'Nothing sensitive.'),
        $this->owner,
        DocumentCategory::Other,
    );

    /*
     * Same team, wrong deal. `scopeBindings()` on the route group is what
     * decides — Laravel resolves `{document}` through `{deal}` and 404s before
     * the controller runs, which is why this asserts a 404 and not a 403: the
     * response says nothing about whether the row exists.
     *
     * The test is here rather than trusted to the framework because the guard
     * is a *route grouping*, and a route moved out of that group looks
     * identical in a diff.
     */
    $this->delete("/deals/{$this->deal->getKey()}/documents/{$document->getKey()}")
        ->assertNotFound();

    expect(Document::query()->count())->toBe(1);
});

it('streams a document back and records who read it', function (): void {
    /*
     * PRD §9 makes document access an audited event, and the entry is written
     * **before** the bytes are handed over: a read that failed halfway is
     * still a read that happened, and a broken stream does not un-see it.
     */
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('report.txt', 'Roof looks sound.'),
        $this->owner,
        DocumentCategory::InspectionReport,
    );

    $response = $this->get("/deals/{$this->deal->getKey()}/documents/{$document->getKey()}");

    $response->assertOk()
        // Attachment, never inline: this serves arbitrary uploads, and a type
        // that renders in a browsing context is a type worth not rendering.
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    /*
     * The directives, not the string: Symfony reorders and normalises
     * `Cache-Control`, so asserting the literal tests the framework's
     * spelling rather than this product's intent. F6.4 wants it private and
     * short-lived, never in a shared cache.
     */
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('max-age=60')
        ->and($cacheControl)->not->toContain('public');

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->getContent())->toBe('Roof looks sound.');

    $entry = AuditEntry::query()->where('action', 'document.accessed')->sole();

    expect($entry->auditable_id)->toBe($document->getKey())
        ->and($entry->actor_person_id)->toBe($this->owner->getKey())
        // PRD §9 keeps PII out of the log. The row holds the filename for
        // anybody entitled to look it up; the entry does not repeat it.
        ->and(json_encode($entry->after, JSON_THROW_ON_ERROR))->not->toContain('report.txt');
});

it('will not hand a document to somebody outside the team', function (): void {
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('report.txt', 'Roof looks sound.'),
        $this->owner,
        DocumentCategory::InspectionReport,
    );

    [$otherTeam, $stranger] = $this->teamWithMember();
    $this->actingAsPerson($stranger, $otherTeam);

    $this->get("/deals/{$this->deal->getKey()}/documents/{$document->getKey()}")
        ->assertNotFound();

    // And nothing was recorded as read, because nothing was read.
    expect(AuditEntry::query()->where('action', 'document.accessed')->count())->toBe(0);
});

it('clears a document gate by attaching the document, end to end', function (): void {
    /*
     * Issue #104, and the check CLAUDE.md says this evaluator was owed: *"if a
     * gate type has exactly one way to be satisfied, verify that path is
     * actually reachable from a route/controller/page — not just evaluated."*
     *
     * `required_tasks_complete` and `ManualConfirmationEvaluator` both shipped
     * unreachable, so this walks the whole way round rather than calling the
     * evaluator: upload through the controller, then advance, and watch the
     * blocker disappear because of it.
     */
    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Listing',
        'state' => WorkflowState::Active,
    ]);

    $stage = Stage::factory()->active()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Listing Preparation',
        'sort_order' => 0,
    ]);

    Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'On Market',
        'sort_order' => 1,
    ]);

    Gate::factory()->ofType('document_present', ['category' => 'disclosure'])->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Seller disclosure on file',
    ]);

    // Blocked, and the blocker says where to go.
    expect(app(DescribeBlockers::class)->forStage($stage->fresh())->canAdvance())->toBeFalse();

    // The route a person actually uses, not the storage service underneath it.
    $this->post("/deals/{$this->deal->getKey()}/documents", [
        'document' => dealUpload('disclosure.txt', 'The seller discloses a prior roof repair.'),
        'category' => DocumentCategory::Disclosure->value,
        'visibility' => DocumentVisibility::Internal->value,
    ])->assertRedirect();

    expect(app(DescribeBlockers::class)->forStage($stage->fresh())->canAdvance())->toBeTrue();
});

it('does not clear the gate with a document of the wrong category', function (): void {
    // The other half, and the one that makes the test above mean something:
    // a gate satisfied by any upload would be a gate satisfied by nothing.
    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'state' => WorkflowState::Active,
    ]);

    $stage = Stage::factory()->active()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'sort_order' => 0,
    ]);

    Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'sort_order' => 1,
    ]);

    Gate::factory()->ofType('document_present', ['category' => 'disclosure'])->create([
        'team_id' => $this->team->getKey(),
        'stage_id' => $stage->getKey(),
        'label' => 'Seller disclosure on file',
    ]);

    $this->post("/deals/{$this->deal->getKey()}/documents", [
        'document' => dealUpload('receipt.txt', 'Paid the photographer.'),
        'category' => DocumentCategory::Receipt->value,
        'visibility' => DocumentVisibility::Internal->value,
    ])->assertRedirect();

    expect(app(DescribeBlockers::class)->forStage($stage->fresh())->canAdvance())->toBeFalse();
});

it('lets a deals-only role download a deal document it can already see', function (): void {
    /*
     * `DocumentPolicy` keyed every document on `properties.*`, which was true
     * while Slice 2's only documents were a property's photographs. S21
     * attached them to deals and the two halves stopped agreeing: the tab
     * authorizes `view` on the **deal**, so a role with `deals.view` and not
     * `properties.view` got a list of documents that then refused to download.
     *
     * No shipped role can reach it — Team Member holds both — but S75 lets a
     * team compose its own, and a permission pair nobody can currently
     * separate is not the same as one nobody ever will.
     */
    $document = app(DocumentStorage::class)->store(
        $this->deal,
        dealUpload('report.txt', 'Roof looks sound.'),
        $this->owner,
        DocumentCategory::InspectionReport,
    );

    $narrow = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($narrow): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $narrow->getKey(),
            'first_name' => 'Dana',
            'last_name' => 'Alvarez',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'deals_only',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                Permissions::VIEW_DEALS,
                Permissions::MANAGE_DEALS,
            ])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($narrow, $this->team);

    // The control: they can open the tab at all.
    $this->get("/deals/{$this->deal->getKey()}/documents")->assertOk();

    // And the thing that was broken: the file the tab just listed.
    $this->get("/deals/{$this->deal->getKey()}/documents/{$document->getKey()}")
        ->assertOk();
});

it('still keys a property’s photograph on the property permissions', function (): void {
    /*
     * The other half of following the subject. A gallery image is not reached
     * by `deals.view`, and the policy change must not have quietly widened it.
     */
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    /*
     * Real PNG bytes. `DocumentStorage` derives the type with `finfo` over the
     * contents, so a `.png` full of text is refused before the policy is ever
     * consulted — the bytes-decide rule biting a test rather than an upload.
     */
    $photo = app(DocumentStorage::class)->store(
        $property,
        dealUpload('front.png', onePixelPng()),
        $this->owner,
        DocumentCategory::Photo,
    );

    $narrow = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($narrow): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $narrow->getKey(),
            'first_name' => 'Dana',
            'last_name' => 'Alvarez',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'deals_only_photo',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [Permissions::VIEW_DEALS])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    expect(app(DocumentPolicy::class)->view($narrow->fresh(), $photo->fresh()))->toBeFalse();
});
