<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\Team;
use App\Support\Documents\UnsupportedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only thing that writes a team's logo (S72 · issue #55's remaining half).
 *
 * `teams.logo_path` shipped with #55 and had **no writer at all** — a column
 * S72 rendered as a value and no screen could set. #97 is where that stops
 * being harmless: S86's headline state is *"per-team logo"*, and a layout that
 * reads a column nothing can fill is `CLAUDE.md`'s S17 finding pointed the
 * other way round. A reader with no writer is as dead as a row nothing can
 * reach, and reads as finished from either end.
 *
 * ## The same disk as a document, and for the same reason
 *
 * A logo is not customer PII, so the argument is not F6.4's. It is that this
 * product has exactly one place uploaded bytes go, one allowlist deciding what
 * they may be, and one route shape that serves them — and a second one
 * invented for a logo would be a second one to keep correct. So it takes
 * {@see \App\Support\Documents\DocumentStorage::DISK}, which is private, and a
 * key that reveals nothing.
 *
 * It does **not** take a `documents` row. A `Document` is polymorphic to a
 * subject, is swept by `HasDocuments` when that subject is deleted, and is
 * counted by the photo gallery; a logo has none of those relationships and
 * would be a row every one of those mechanisms had to learn to skip. The
 * column on `teams` is the record.
 *
 * ## What a logo may be, and why not SVG
 *
 * Raster only. An SVG is a document that can carry `<script>` and external
 * references, and this one would be rendered into a client's email and served
 * back into a colleague's browser. The three raster types below are what a
 * design tool exports and what every mail client draws.
 */
final class TeamLogo
{
    /** Where a logo lives. Private, like everything else uploaded. */
    public const DISK = 'documents';

    /** 1MB. A logo that needs more than this is the wrong asset for an email. */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * Detected type to extension, keyed by the bytes and never the filename.
     *
     * `DocumentStorage` learned this the hard way and the note there is worth
     * reading: an allowlist checked against the browser's claim is a denylist
     * with extra steps.
     *
     * @var array<string, string>
     */
    public const IMAGE_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
    ];

    /** The directory inside the team's own, so a logo is never mistaken for a document. */
    private const FOLDER = 'branding';

    /**
     * Replace a team's logo, returning the new key.
     *
     * @throws UnsupportedDocument when the file is not something this accepts
     */
    public function store(Team $team, UploadedFile $file): string
    {
        $detected = mb_strtolower((string) $file->getMimeType());

        if (! array_key_exists($detected, self::IMAGE_TYPES)) {
            throw UnsupportedDocument::logoType();
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw UnsupportedDocument::logoTooLarge(self::MAX_BYTES);
        }

        $previous = $team->logo_path;

        $path = $file->storeAs(
            $team->getKey().'/'.self::FOLDER,
            Str::ulid()->toString().'.'.self::IMAGE_TYPES[$detected],
            ['disk' => self::DISK],
        );

        if (! is_string($path)) {
            throw UnsupportedDocument::unwritable();
        }

        $team->forceFill(['logo_path' => $path])->save();

        /*
         * The old file goes after the new one is recorded, never before. A
         * delete-then-write that fails halfway leaves a team with a column
         * pointing at bytes that are gone, which renders as a broken image in
         * every client email until somebody uploads again.
         */
        $this->forget($previous);

        return $path;
    }

    public function delete(Team $team): void
    {
        $path = $team->logo_path;

        $team->forceFill(['logo_path' => null])->save();

        $this->forget($path);
    }

    public function exists(Team $team): bool
    {
        $path = $team->logo_path;

        return is_string($path) && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /** The bytes, or null when there is no logo or the file has gone. */
    public function contents(Team $team): ?string
    {
        if (! $this->exists($team)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get((string) $team->logo_path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * The type to serve and to embed, from the stored extension.
     *
     * The extension came from the detected mime on the way in, so this is a
     * lookup rather than a guess — and a path with an extension outside the
     * list returns null rather than defaulting, because a wrong
     * `Content-Type` on bytes a browser renders is the whole point of having
     * an allowlist.
     */
    public function mimeType(Team $team): ?string
    {
        $extension = mb_strtolower(pathinfo((string) $team->logo_path, PATHINFO_EXTENSION));

        foreach (self::IMAGE_TYPES as $mime => $known) {
            if ($known === $extension) {
                return $mime;
            }
        }

        return null;
    }

    private function forget(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
