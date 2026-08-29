<?php

declare(strict_types=1);

use App\Support\Extraction\Redaction\RedactedDocument;
use App\Support\Extraction\Redaction\Redactor;
use Tests\Support\Sources;

/**
 * No document reaches a third-party model unredacted (#114 · PRD §9, §8.4).
 *
 * ## What the type system already decides, and what it cannot
 *
 * #114 asks for redaction *"enforced structurally, not by convention"*, and
 * half of that is done in the signature:
 * `ExtractionProvider::extract(RedactedDocument $document, …)`. There is no
 * argument you can hand a provider that has not been redacted, and a provider
 * added next slice inherits that without its author knowing the rule exists.
 * That is the important half.
 *
 * The half a type cannot decide is whether a `RedactedDocument` can be
 * **minted** out of raw bytes. PHP has no package-private and no friend
 * classes, so `RedactedDocument::of()` has to be public for `Redactor` to call
 * it, and once it is public anything can. This test is what closes that.
 *
 * It is written down in the class's own docblock as well as here, because a
 * guard nobody knows about is a guard somebody routes around.
 */
$sanctioned = ['Support/Extraction/Redaction/Redactor.php'];

it('lets only the redactor mint a redacted document', function () use ($sanctioned): void {
    $callers = [];

    foreach (Sources::files(['app'], ['php']) as $relative) {
        // The class defines the factory; defining it is not calling it.
        if ($relative === 'Support/Extraction/Redaction/RedactedDocument.php') {
            continue;
        }

        $contents = (string) file_get_contents(app_path($relative));

        if (preg_match('/RedactedDocument::of\s*\(/', $contents) === 1) {
            $callers[] = $relative;
        }
    }

    sort($callers);

    expect($callers)->toBe($sanctioned);
});

it('keeps the redacted document’s constructor private', function (): void {
    /*
     * Belt to the braces above: if the constructor were public, a caller could
     * skip the factory entirely and the source scan would see nothing. A
     * reflection assertion catches that where a regex would not.
     */
    $constructor = (new ReflectionClass(RedactedDocument::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->isPrivate())->toBeTrue();
});

it('keeps every provider away from the document’s bytes', function (): void {
    /*
     * The failure this catches is a provider that decided it needed the
     * original file — for a vision call, say, which is a real and reasonable
     * thing to want.
     *
     * It would be a reasonable thing to *build*, too. What it must not be is a
     * thing somebody builds quietly: sending a PDF's bytes rather than its
     * redacted words is a different disclosure with a different argument
     * behind it, and PRD §9's *"no document reaches a third-party model
     * without redaction"* would need answering in the new shape first. So the
     * guard fails, loudly, and the conversation happens.
     */
    $offenders = [];

    foreach (Sources::files(['app'], ['php']) as $relative) {
        if (! str_starts_with($relative, 'Support/Extraction/Providers/')) {
            continue;
        }

        $contents = (string) file_get_contents(app_path($relative));

        foreach (['DocumentStorage', 'file_get_contents', 'ReadableText', 'Storage::'] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $offenders[] = $relative.' reaches for '.$forbidden;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('can tell a violation from a clean tree', function () use ($sanctioned): void {
    /*
     * The positive control. Two of the three assertions above pass on an empty
     * result set — a `Sources::files()` signature change, or a rename of the
     * factory, would leave them green and blind.
     */
    $files = Sources::files(['app'], ['php']);

    expect($files)->toContain($sanctioned[0])
        ->and($files)->toContain('Support/Extraction/Providers/AnthropicProvider.php')
        ->and((string) file_get_contents(app_path($sanctioned[0])))->toContain('RedactedDocument::of')
        ->and(class_exists(Redactor::class))->toBeTrue();
});
