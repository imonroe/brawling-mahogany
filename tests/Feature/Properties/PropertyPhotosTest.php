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

/**
 * A **real** upload over a temp file, so `getMimeType()` runs `finfo`.
 *
 * `Illuminate\Http\Testing\File::getMimeType()` returns the type it was
 * handed, or one derived from the *filename* — it never reads the bytes. So
 * every assertion about content-based validation written with `fake()` passes
 * over a code path production does not take, which is how a HEIF brand the
 * allowlist did not carry got past a green suite.
 */
function realUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');

    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * The first bytes of a HEIF file with the given major brand.
 *
 * `finfo` reads the brand out of the `ftyp` box and nothing else, so this is
 * enough to reproduce what an encoder writes: `heic` reports `image/heic`,
 * `mif1` reports `image/heif`, `msf1` reports `image/heif-sequence`.
 */
function heifBytes(string $brand): string
{
    return "\x00\x00\x00\x18ftyp".$brand."\x00\x00\x00\x00".$brand
        .'mif1miaf'.str_repeat("\x00", 64);
}

it('takes an iPhone photograph, whichever HEIF brand wrote it', function (): void {
    /*
     * The regression that made this test exist. Moving validation from the
     * filename to the bytes is right — an allowlist checked against the
     * browser's claim is a denylist with extra steps — but the map was keyed
     * by extension and searched backwards, so it carried exactly one of the
     * three types `finfo` reports for HEIF. An `mif1`-brand file, which is
     * what Apple writes at least as often as `heic`, refused itself with a
     * message naming HEIC as accepted.
     */
    foreach (['heic', 'mif1'] as $brand) {
        $this->post("/properties/{$this->property->getKey()}/photos", [
            'photo' => realUpload("front-{$brand}.heic", heifBytes($brand)),
        ])->assertRedirect();
    }

    expect(Document::query()->count())->toBe(2);

    /*
     * And a Live Photo is refused, deliberately rather than by omission:
     * `msf1` is a video sequence, and this product does not transcode. A
     * moving image stored under `.heic` and called a photograph is worse than
     * a sentence saying no.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => realUpload('live.heic', heifBytes('msf1')),
    ])->assertSessionHasErrors();

    expect(Document::query()->count())->toBe(2);
});

it('reads the bytes rather than the name, on a real upload', function (): void {
    /*
     * The guard that would have caught the HEIF regression while it was being
     * written, and the one every `fake()`-based assertion here cannot be: a
     * fake's `getMimeType()` answers from the filename, so a broken
     * implementation that trusted the client passes them all.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => realUpload('front.jpg', '<html><script>alert(1)</script></html>'),
    ])->assertSessionHasErrors();

    expect(Document::query()->count())->toBe(0);

    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    );

    // Named `.txt`, and stored as the PNG it actually is.
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => realUpload('front.txt', $png),
    ])->assertRedirect();

    expect(Document::query()->sole()->mime_type)->toBe('image/png')
        ->and(Document::query()->sole()->path)->toEndWith('.png');
});

it('does not trust the browser about what a file is', function (): void {
    /*
     * **The bytes decide, not the filename or the header.**
     *
     * This test used to assert something weaker and wrong: that a `.jpg`
     * carrying a `text/html` content-type was *stored* as `image/jpeg`. It
     * was — because the allowlist was checked against the browser's
     * `getClientOriginalExtension()`, which is a string the uploader chose.
     * An allowlist checked against a claim is a denylist with extra steps, and
     * the row it wrote said `image/jpeg` of a file that was not one.
     *
     * So the upload is refused now, and the assertion is that nothing was
     * stored — which a broken implementation cannot produce, unlike the old
     * `toBe('image/jpeg')`, which it produced happily.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => UploadedFile::fake()->create('front.jpg', 40, 'text/html'),
    ])->assertSessionHasErrors();

    expect(Document::query()->count())->toBe(0);

    // And a real image still gets **our** type rather than the browser's,
    // which is the half of the original claim that was true.
    $this->post("/properties/{$this->property->getKey()}/photos", [
        'photo' => UploadedFile::fake()->image('front.jpg', 80, 60),
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

it('takes the photos with the property, row and bytes', function (): void {
    /*
     * `documents.documentable_id` is polymorphic, so **no foreign key reaches
     * it**: nothing cascades, and `records:purge` finds a row by its
     * `deleted_at` — which a document whose parent was deleted does not have.
     * So deleting a property left live rows pointing at a parent that no
     * longer existed, and their bytes on the disk permanently.
     *
     * `HasDocuments` is the fix and it is a trait rather than a line in
     * `PropertyController::destroy()`, because Slice 3 makes deals
     * documentable and a rule written into one caller is a rule the second
     * caller is written without.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])
        ->assertRedirect();

    $path = Document::query()->sole()->path;

    $this->delete("/properties/{$this->property->getKey()}")->assertRedirect();

    // The row soft-deletes for PRD §9's window; the bytes go now.
    expect(Document::query()->count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);

    Storage::disk(DocumentStorage::DISK)->assertMissing($path);
});

it('sweeps a purged team’s uploads off their own disk', function (): void {
    /*
     * Uploads live on their **own** private disk — the whole point of
     * `filesystems.disks.documents` being separate — so `records:purge`'s two
     * `Storage::` calls for exports and imports never reached them. The rows
     * were deleted with the rest of the team's tables and the bytes outlived
     * the team that owned them, indefinitely: the opposite of what PRD §9's
     * *"then hard delete"* and F6.4's private bucket promise.
     */
    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => photoUpload()])
        ->assertRedirect();

    $path = Document::query()->sole()->path;

    // A team is purged on `purge_after`, which the console sets when it
    // schedules the deletion — not on `deleted_at`.
    $this->team->forceFill(['purge_after' => now()->subDay()])->saveQuietly();

    $this->artisan('records:purge')->assertSuccessful();

    Storage::disk(DocumentStorage::DISK)->assertMissing($path);

    // Nothing of theirs left anywhere on it, which is the claim the belt-and-
    // braces `deleteDirectory` makes: a file whose row was already gone has
    // nothing left to find it by.
    expect(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});
