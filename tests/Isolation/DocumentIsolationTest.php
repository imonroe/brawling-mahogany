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
 * Nobody reads another team's files (PRD §4.6 F6.4, §9 · #42 · issue #63).
 *
 * #63's definition of done defers to #42 for this, and it is the case worth
 * the most: an uploaded file is the highest-risk thing in the product, and a
 * download endpoint is the one place a team id is easy to leave out because
 * the id in the URL "already identifies the row".
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);
});

it('refuses to download another team’s photo', function (): void {
    [$mine, $me] = $this->teamWithMember();
    [$theirs, $them] = $this->teamWithMember();

    $theirProperty = app(TeamContext::class)->runFor(
        $theirs,
        fn (): Property => Property::factory()->create(['team_id' => $theirs->getKey()]),
    );

    $this->actingAsPerson($them, $theirs);

    $this->post("/properties/{$theirProperty->getKey()}/photos", [
        'photo' => UploadedFile::fake()->image('theirs.jpg'),
    ])->assertRedirect();

    $photo = Document::withoutTeamScope()->sole();

    // Now somebody else entirely, holding the ids.
    $this->actingAsPerson($me, $mine);

    $this->get("/properties/{$theirProperty->getKey()}/photos/{$photo->getKey()}")
        ->assertNotFound();

    $this->delete("/properties/{$theirProperty->getKey()}/photos/{$photo->getKey()}")
        ->assertNotFound();

    // And nothing was written to the audit log claiming they read it.
    expect(DB::table('audit_log')->where('action', 'document.accessed')->count())->toBe(0);

    // The bytes are still there, because the delete never happened.
    Storage::disk(DocumentStorage::DISK)->assertExists($photo->path);
});

it('lets the team that owns it do both', function (): void {
    /*
     * The control, and it is not optional: an endpoint that 404s for everybody
     * passes the case above perfectly.
     */
    [$theirs, $them] = $this->teamWithMember();

    $property = app(TeamContext::class)->runFor(
        $theirs,
        fn (): Property => Property::factory()->create(['team_id' => $theirs->getKey()]),
    );

    $this->actingAsPerson($them, $theirs);

    $this->post("/properties/{$property->getKey()}/photos", [
        'photo' => UploadedFile::fake()->image('theirs.jpg'),
    ])->assertRedirect();

    $photo = Document::query()->sole();

    $this->get("/properties/{$property->getKey()}/photos/{$photo->getKey()}")->assertOk();

    expect(DB::table('audit_log')->where('action', 'document.accessed')->count())->toBe(1);
});

it('does not let a photo be reached through the wrong property', function (): void {
    /*
     * Two properties in the same team pass the tenancy layers and the policy
     * alike. Only the nesting answers whose property a photo is on, which is
     * the second question `scopeBindings()` exists for.
     */
    [$team, $member] = $this->teamWithMember();

    [$one, $two] = app(TeamContext::class)->runFor($team, fn (): array => [
        Property::factory()->create(['team_id' => $team->getKey()]),
        Property::factory()->create(['team_id' => $team->getKey()]),
    ]);

    $this->actingAsPerson($member, $team);

    $this->post("/properties/{$one->getKey()}/photos", [
        'photo' => UploadedFile::fake()->image('one.jpg'),
    ])->assertRedirect();

    $photo = Document::query()->sole();

    $this->get("/properties/{$two->getKey()}/photos/{$photo->getKey()}")->assertNotFound();
});
