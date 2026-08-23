<?php

declare(strict_types=1);

use App\Support\Links\SafeUrl;

/**
 * What may become an `href` (issue #61 · PRD §7.13).
 *
 * A URL a team types is rendered as a link on S36, and a link is not inert.
 * The allowlist is the rule — http and https, nothing else — because a
 * denylist has to predict the next scheme somebody tries.
 */
dataset('permitted urls', [
    'https' => ['https://zillow.test/123'],
    'http' => ['http://assessor.example/parcel/1'],
    'mixed case scheme' => ['HtTpS://zillow.test/123'],
    'query and fragment' => ['https://mls.test/search?id=1#photos'],
    'port' => ['https://intranet.test:8443/listing'],
]);

dataset('refused urls', [
    // Script execution in the reader's session. Laravel's `url` rule accepts
    // this; that is the whole reason this class exists.
    'javascript' => ['javascript:alert(1)'],
    'uppercase javascript' => ['JavaScript:alert(1)'],
    // The same attack with a different spelling.
    'data html' => ['data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['vbscript:msgbox(1)'],
    // Reads a file off whatever machine opens it.
    'file' => ['file:///etc/passwd'],
    // A newline survives `parse_url` as a relative path and is stripped by
    // more than one browser, leaving `javascript:`.
    'embedded newline' => ["java\nscript:alert(1)"],
    'tab inside the scheme' => ["java\tscript:alert(1)"],
    // Parses, but there is nothing to follow.
    'no host' => ['https:///listing'],
    'scheme only' => ['https://'],
    'bare path' => ['/properties/1'],
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('permits a link somebody can follow', function (string $url): void {
    expect(SafeUrl::permits($url))->toBeTrue();
})->with('permitted urls');

it('refuses everything else', function (string $url): void {
    expect(SafeUrl::permits($url))->toBeFalse();
})->with('refused urls');

it('refuses a null', function (): void {
    expect(SafeUrl::permits(null))->toBeFalse();
});

it('says what to do about it', function (): void {
    // IA §10: what happened, then what to do.
    expect(SafeUrl::message())->toContain('https://');
});
