<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Property;
use App\Support\Documents\DocumentStorage;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
    Storage::fake(DocumentStorage::DISK);
    $this->property = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Property => Property::factory()->create(['team_id' => $this->team->getKey()]),
    );
});

it('SCRATCH accepts html bytes named .jpg', function (): void {
    $file = UploadedFile::fake()->createWithContent('front.jpg', '<html><script>alert(1)</script></html>');

    dump('reported mime: '.$file->getMimeType());

    $this->post("/properties/{$this->property->getKey()}/photos", ['photo' => $file])
        ->assertRedirect();

    dump('documents stored: '.Document::query()->count());
    dump('stored mime: '.(Document::query()->first()?->mime_type ?? 'none'));
});
