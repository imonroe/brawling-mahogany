<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Property;
use App\Support\Documents\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * S50 — the team-wide documents index (PRD §4.6 F6.1 · issue #98).
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

function indexUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

function storeOn(object $subject, string $name, DocumentCategory $category, DocumentVisibility $visibility = DocumentVisibility::Internal): Document
{
    return app(DocumentStorage::class)->store(
        $subject,
        indexUpload($name, 'Nothing sensitive in here at all.'),
        test()->member,
        $category,
        $visibility,
    );
}

it('lists everything the team holds, whatever it hangs off', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    storeOn($this->deal, 'disclosure.txt', DocumentCategory::Disclosure);
    storeOn($property, 'survey.txt', DocumentCategory::Other);

    $this->get('/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Index')
            ->has('documents', 2)
            ->where('total', 2),
        );
});

it('reports storage used as a number, never as a quota', function (): void {
    /*
     * Screen Inventory lists it as a state and it is deliberately reported
     * rather than enforced: there is no plan tier to exceed, so a bar toward
     * an invented limit would be a lie somebody later builds a billing
     * assumption on.
     */
    storeOn($this->deal, 'one.txt', DocumentCategory::Other);

    $expected = (int) Document::query()->sum('size_bytes');

    expect($expected)->toBeGreaterThan(0);

    $this->get('/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('storageUsed', $expected));
});

it('stops counting storage the moment the bytes are gone', function (): void {
    /*
     * `DocumentStorage::remove()` deletes the file immediately and soft-deletes
     * the row for PRD §9's thirty days. Counting the trashed row would report
     * storage nobody is using — the global scope gives the right answer for
     * free, and this is the test that says so out loud.
     */
    $document = storeOn($this->deal, 'one.txt', DocumentCategory::Other);

    app(DocumentStorage::class)->remove($document);

    $this->get('/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('storageUsed', 0)->has('documents', 0));
});

it('filters by category, by deal, and by what a person can remember of the name', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    storeOn($this->deal, 'roof-inspection.txt', DocumentCategory::InspectionReport);
    storeOn($this->deal, 'sellers-disclosure.txt', DocumentCategory::Disclosure);
    storeOn($property, 'flyer.txt', DocumentCategory::Marketing);

    $this->get('/documents?category='.DocumentCategory::Disclosure->value)
        ->assertInertia(fn ($page) => $page
            ->has('documents', 1)
            ->where('documents.0.name', 'sellers-disclosure.txt'),
        );

    $this->get('/documents?deal='.$this->deal->getKey())
        ->assertInertia(fn ($page) => $page->has('documents', 2));

    // Case-insensitive, because nobody remembers the capitals.
    $this->get('/documents?q=ROOF')
        ->assertInertia(fn ($page) => $page
            ->has('documents', 1)
            ->where('documents.0.name', 'roof-inspection.txt'),
        );
});

it('keeps every other filter when one of them changes', function (): void {
    // Narrowing by category must not silently forget which deal you were on.
    storeOn($this->deal, 'a-disclosure.txt', DocumentCategory::Disclosure);
    storeOn($this->deal, 'a-receipt.txt', DocumentCategory::Receipt);

    $other = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    storeOn($other, 'b-disclosure.txt', DocumentCategory::Disclosure);

    $this->get('/documents?deal='.$this->deal->getKey().'&category='.DocumentCategory::Disclosure->value)
        ->assertInertia(fn ($page) => $page
            ->has('documents', 1)
            ->where('documents.0.name', 'a-disclosure.txt')
            // And the screen is told what it is filtered by, so it can say so.
            ->where('filters.deal', $this->deal->getKey())
            ->where('filters.category', DocumentCategory::Disclosure->value),
        );
});

it('offers only the deals that actually have documents', function (): void {
    // A picker full of empty answers is a picker nobody uses.
    Deal::factory()->create(['team_id' => $this->team->getKey()]);

    storeOn($this->deal, 'one.txt', DocumentCategory::Other);

    $this->get('/documents')
        ->assertInertia(fn ($page) => $page
            ->has('deals', 1)
            ->where('deals.0.id', $this->deal->getKey()),
        );
});

it('gives every row a way back to whatever it hangs off', function (): void {
    storeOn($this->deal, 'one.txt', DocumentCategory::Other);

    $this->get('/documents')
        ->assertInertia(fn ($page) => $page
            ->where('documents.0.subjectUrl', '/deals/'.$this->deal->getKey().'/documents'),
        );
});

it('shows one team nothing of another team’s', function (): void {
    storeOn($this->deal, 'ours.txt', DocumentCategory::Other);

    [$otherTeam, $stranger] = $this->teamWithMember();
    $this->actingAsPerson($stranger, $otherTeam);

    $this->get('/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('documents', 0)->where('storageUsed', 0));
});

it('shows one document, with a preview decided by the stored type', function (): void {
    $document = storeOn($this->deal, 'notes.txt', DocumentCategory::Other);

    $this->get("/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('document.name', 'notes.txt')
            /*
             * Derived from the bytes by `finfo` at upload, never from the
             * filename — which is what makes it safe to decide a preview from.
             */
            ->where('document.mimeType', 'text/plain')
            ->where('document.missing', false)
            // The bytes come from the subject's own audited route. One path to
            // a file, one place the authorization lives.
            ->where('downloadUrl', '/deals/'.$this->deal->getKey().'/documents/'.$document->getKey()),
        );
});

it('says the file is gone rather than drawing an empty frame', function (): void {
    $document = storeOn($this->deal, 'notes.txt', DocumentCategory::Other);

    Storage::disk(DocumentStorage::DISK)->delete($document->path);

    $this->get("/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('document.missing', true));
});

it('audits a change of visibility, because publishing is a disclosure decision', function (): void {
    /*
     * The moment somebody outside the team can read a seller's inspection
     * report. PRD §9 wants the record of who decided that — and the reverse
     * direction too, since "who un-shared this" is the same question asked
     * after the fact.
     */
    $document = storeOn($this->deal, 'disclosure.txt', DocumentCategory::Disclosure);

    expect($document->visibility)->toBe(DocumentVisibility::Internal);

    $this->patch("/documents/{$document->getKey()}/visibility", [
        'visibility' => DocumentVisibility::ClientVisible->value,
    ])->assertRedirect();

    expect($document->fresh()->visibility)->toBe(DocumentVisibility::ClientVisible);

    $entry = AuditEntry::query()->where('action', 'document.visibility_changed')->sole();

    expect($entry->auditable_id)->toBe($document->getKey())
        ->and($entry->actor_person_id)->toBe($this->member->getKey());
});

it('will not show one team a document belonging to another', function (): void {
    $document = storeOn($this->deal, 'ours.txt', DocumentCategory::Other);

    [$otherTeam, $stranger] = $this->teamWithMember();
    $this->actingAsPerson($stranger, $otherTeam);

    $this->get("/documents/{$document->getKey()}")->assertNotFound();

    $this->patch("/documents/{$document->getKey()}/visibility", [
        'visibility' => DocumentVisibility::ClientVisible->value,
    ])->assertNotFound();

    expect($document->fresh()->visibility)->toBe(DocumentVisibility::Internal);
});
