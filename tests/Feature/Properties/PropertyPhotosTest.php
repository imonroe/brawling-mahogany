<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Property;
use App\Support\Documents\DocumentStorage;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * S38 — property photographs, and the storage service under them (PRD §4.6
 * F6.4–F6.6, §9 · issue #63).
 *
 * F6.4 is one sentence — *"no public buckets, every download authorized and
 * short-lived"* — and most of this file is that sentence taken apart: the key
 * says nothing, the only way to read a file is a route that asks the policy,
 * and every read is in the audit log.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    Storage::fake(DocumentStorage::DISK);

    $this->property = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Property => Property::factory()->create(['team_id' => $this->team->getKey()]),
    );
});

function photoUpload(string $name = 'front.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 800, 600);
}

it('stores a photo under a key that says nothing about it', function (): void {
    /*
     * F6.4: a leaked key must reveal nothing — not
     * `team-3/123-main-st/sellers-bank-statement.pdf`. So the key is the team
     * and a ULID, and the name a person typed lives in the database.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => photoUpload('123-main-st-sellers-bank-statement.jpg'),
    ])->assertRedirect();

    $document = Document::query()->sole();

    expect($document->path)->toStartWith($this->team->getKey().'/')
        ->and($document->path)->not->toContain('main-st')
        ->and($document->path)->not->toContain('bank')
        // The name is kept for the person who uploaded it, and never used to
        // build the key.
        ->and($document->original_name)->toBe('123-main-st-sellers-bank-statement.jpg')
        ->and($document->disk)->toBe(DocumentStorage::DISK);

    Storage::disk(DocumentStorage::DISK)->assertExists($document->path);
});

it('takes photographs and refuses everything else', function (): void {
    /*
     * #63: *"do not ship a general-purpose upload UI in this slice, only the
     * property photo path, so no user learns to upload a contract before the
     * guardrails exist."* An allowlist, because a denylist is a list of the
     * things somebody thought of.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('photo');

    expect(Document::query()->count())->toBe(0);
});

it('does not trust the browser about what a file is', function (): void {
    // The type comes from the extension allowlist, not from the upload's own
    // claim — a mislabelled `Content-Type` must not become the one we serve.
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => UploadedFile::fake()->create('front.jpg', 40, 'text/html'),
    ])->assertRedirect();

    expect(Document::query()->sole()->mime_type)->toBe('image/jpeg');
});

it('makes the first photo the primary one, without being asked', function (): void {
    // A property with photos and no primary renders no card image, which is a
    // state somebody has to notice and fix by hand.
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])
        ->assertRedirect();
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload('side.jpg')])
        ->assertRedirect();

    $photos = Document::query()->orderBy('sort_order')->get();

    expect($photos->first()->is_primary)->toBeTrue()
        ->and($photos->last()->is_primary)->toBeFalse();
});

it('moves the primary flag rather than letting two rows carry it', function (): void {
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])->assertRedirect();
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload('side.jpg')])->assertRedirect();

    $second = Document::query()->orderByDesc('sort_order')->first();

    $this->post("/properties/{$this->property->getKey()}/photos/{$second->getKey()}/primary")
        ->assertRedirect();

    expect(Document::query()->where('is_primary', true)->count())->toBe(1)
        ->and($second->refresh()->is_primary)->toBeTrue();
});

it('takes the whole order at once', function (): void {
    /*
     * One intention rather than a move-one endpoint: two adjacent swaps racing
     * each other produce an order neither person chose.
     */
    foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $name) {
        $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload($name)])
            ->assertRedirect();
    }

    $ids = Document::query()->orderBy('sort_order')->pluck('id')->all();

    $this->patch("/properties/{$this->property->getKey()}/photos", [
        'ids' => [$ids[2], $ids[0], $ids[1]],
    ])->assertRedirect();

    expect(Document::query()->orderBy('sort_order')->pluck('id')->all())
        ->toBe([$ids[2], $ids[0], $ids[1]]);
});

it('promotes a survivor when the primary photo is removed', function (): void {
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])->assertRedirect();
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload('side.jpg')])->assertRedirect();

    $primary = Document::query()->where('is_primary', true)->sole();

    $this->delete("/properties/{$this->property->getKey()}/photos/{$primary->getKey()}")
        ->assertRedirect();

    expect(Document::query()->where('is_primary', true)->count())->toBe(1);
});

it('deletes the bytes now, and keeps the row for the retention window', function (): void {
    /*
     * PRD §9's thirty days is about the record. A soft-deleted row whose bytes
     * are still readable by anything holding the key is a deletion that did
     * not delete, and the key is the only thing an object store checks.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])->assertRedirect();

    $photo = Document::query()->sole();
    $path = $photo->path;

    $this->delete("/properties/{$this->property->getKey()}/photos/{$photo->getKey()}")->assertRedirect();

    Storage::disk(DocumentStorage::DISK)->assertMissing($path);

    expect(Document::withTrashed()->count())->toBe(1)
        ->and(Document::query()->count())->toBe(0);
});

it('audits every read, because PRD §9 says access is an event', function (): void {
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])->assertRedirect();

    $photo = Document::query()->sole();

    $this->get("/properties/{$this->property->getKey()}/photos/{$photo->getKey()}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $entry = DB::table('audit_log')->where('action', 'document.accessed')->sole();

    expect($entry->auditable_id)->toBe($photo->getKey())
        ->and($entry->actor_person_id)->toBe($this->member->getKey());
});

it('serves a file no other way', function (): void {
    /*
     * F6.4's *"no public buckets"* is a property of there being exactly one
     * way to read a file. The model must expose nothing a browser could
     * fetch — no `url()`, no accessor — or the audit entry above is a record
     * of the reads that happened to go through the front door.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])->assertRedirect();

    $photo = Document::query()->sole();

    expect(method_exists($photo, 'url'))->toBeFalse()
        ->and(array_key_exists('url', $photo->toArray()))->toBeFalse()
        ->and(config('filesystems.disks.'.DocumentStorage::DISK.'.url'))->toBeNull()
        ->and(config('filesystems.disks.'.DocumentStorage::DISK.'.visibility'))->toBeNull();
});
