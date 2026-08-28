<?php

declare(strict_types=1);

namespace App\Support\Extraction\Providers;

use App\Support\Extraction\Contracts\ExtractionPrompt;
use App\Support\Extraction\Contracts\ExtractionProvider;
use App\Support\Extraction\ProviderFailed;
use App\Support\Extraction\ProviderResult;
use App\Support\Extraction\Redaction\RedactedDocument;

/**
 * The default, and it refuses.
 *
 * PRD §10 lists four things that must exist before F10 ships — a signed DPA, a
 * provider contractually barred from training on submitted content, a retention
 * position, and disclosure language in the team's own listing agreement (#13).
 * None is a code change. None can be checked from inside this application.
 *
 * So the question this class answers is: *what should happen when nobody has
 * said which provider, on what terms?* A default that reached a live API would
 * make the absence of that decision into a decision — somebody's contract goes
 * to a third party because a config key was never set. This makes the absence
 * of a decision mean nothing leaves.
 *
 * It is not a test double. `tests/` uses `Http::fake()` against the real
 * `AnthropicProvider`, because a test that exercises a stub proves nothing
 * about the code that ships (the `Mail::fake()` trap CLAUDE.md records, one
 * subsystem over). This is the production default, and the message is written
 * for the person who meets it.
 */
final class NullProvider implements ExtractionProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function model(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function extract(RedactedDocument $document, ExtractionPrompt $prompt): ProviderResult
    {
        throw ProviderFailed::notConfigured();
    }
}
