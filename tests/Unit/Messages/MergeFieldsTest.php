<?php

declare(strict_types=1);

use App\Support\Messages\MergeField;
use App\Support\Messages\MergeFields;

/**
 * F5.6's merge fields, the half that needs no database (issue #90).
 *
 * Extraction, well-formedness and substitution decide whether `{{ client name }}`
 * reaches somebody's inbox, and none of them needs a deal to answer. What a
 * field *resolves to* is `tests/Feature/Messages/MessageRenderingTest.php`,
 * which needs one.
 */
it('registers the field F5.6 names that cannot resolve yet, saying why', function (): void {
    /*
     * Registered rather than omitted, so the editor can say what is missing and
     * the validator can refuse it **by name**. "There is no such field" would
     * send somebody looking for a spelling mistake.
     *
     * There were two of these through Slice 3, both waiting on Slice 4. #109
     * wired `next_deadline`, so this asserts the **whole** deferred list rather
     * than membership in it: a field quietly becoming unavailable again — an
     * `availableFrom` added while chasing a bug, and left — is a merge field
     * every existing template using it now fails to save with.
     */
    $deferred = array_values(array_filter(
        MergeFields::all(),
        static fn (MergeField $field): bool => ! $field->isAvailable(),
    ));

    expect(array_map(static fn (MergeField $f): string => $f->token, $deferred))
        ->toBe(['status_page_link']);

    /*
     * And it says why *now*, not which slice: the status page shipped in Slice
     * 4 and this field did not, because what is missing is a credential a
     * message may carry rather than a screen. A note naming a slice that has
     * landed reads as an oversight and gets "fixed" by deleting the guard.
     */
    expect($deferred[0]->availableFrom)->toContain('#110')
        ->and($deferred[0]->availableFrom)->not->toContain('Slice 4');
});

it('keeps every merge field token in snake_case', function (): void {
    // Same rule as the enums (IA §8), and here it is what makes the strict
    // token pattern and the registry agree: a `Client_Name` in the registry
    // would be a field the validator refuses as malformed.
    foreach (MergeFields::all() as $field) {
        expect($field->token)->toBeSnakeCase()
            ->and(MergeFields::isWellFormed($field->token))->toBeTrue();
    }
});

it('finds a malformed token as well as a well-formed one', function (): void {
    // The loose extraction is the whole defence: a validator that scanned for
    // *well-formed* tokens would see nothing wrong with `{{ client name }}`
    // and let the braces through into somebody's inbox.
    expect(MergeFields::extract('Hi {{ client_name }}, about {{ client name }} and {{}}'))
        ->toBe(['client_name', 'client name', '']);

    expect(MergeFields::isWellFormed('client_name'))->toBeTrue()
        ->and(MergeFields::isWellFormed('client name'))->toBeFalse()
        ->and(MergeFields::isWellFormed('Client_Name'))->toBeFalse()
        ->and(MergeFields::isWellFormed(''))->toBeFalse();
});

/**
 * The typo that used to save clean and reach a client's inbox.
 *
 * `TOKEN_PATTERN` was loose about what sits *between* the braces and strict
 * about the braces themselves, which is half a defence: an unclosed run
 * matched nothing, so the validator saw a clean template, the renderer had
 * nothing to substitute, and `isComplete()` said the message was fine —
 * pre-arming #93's approval gate to release it.
 */
it('finds a brace run that was never a pair', function (string $body, array $expected): void {
    expect(MergeFields::strayBraceRuns($body))->toBe($expected);
})->with([
    'a dropped closing brace' => ['Hi {{ client_name }', ['{{']],
    'no closing at all' => ['Hi {{client_name', ['{{']],
    'a split opening brace' => ['Hi { { client_name }}', ['}}']],

    // The controls. A well-formed token has nothing left over, and a third
    // brace is a token followed by a literal one — which renders as somebody
    // probably meant and is not what this exists to catch.
    'well formed (control)' => ['Hi {{ client_name }}', []],
    'a trailing literal brace (control)' => ['Hi {{ client_name }}}', []],
    'no braces at all (control)' => ['Hi there', []],
    'a lone closing brace (control)' => ['a } b', []],
]);

/**
 * The other half of the brace check, and the regression the first version of
 * it caused.
 *
 * Design System §12 allows a `<style>` block as a progressive enhancement, and
 * nested CSS rules close with `}}` — which the closing half of the check read
 * as a stray brace and refused, on the one field HTML email is written into.
 * An unclosed **opening** has no such collision: nothing in CSS or HTML
 * produces `{{`.
 */
it('takes the style block out rather than half the check', function (string $body, array $expected): void {
    expect(MergeFields::strayBraceRuns($body, markup: true))->toBe($expected);
})->with([
    // The regression the first fix caused: Design System §12 allows a
    // `<style>` block, and nested rules close with `}}`.
    'a media query' => ['<style>@media (max-width:600px){.card{width:100%}}</style><p>Hi {{ client_name }}</p>', []],
    'keyframes' => ['<style>@keyframes p{0%{opacity:0}100%{opacity:1}}</style>', []],

    /*
     * The two the *second* fix had to keep. Dropping the closing half for
     * markup would have let both through — and `{ client_name }}` needs no odd
     * spacing at all, which makes it exactly as likely as the opening-side
     * typo the check was written for. That is round 1's blocker, alive on the
     * one field HTML email is written into.
     */
    'one opening brace' => ['<p>Hi { client_name }}</p>', ['}}']],
    'a split opening brace' => ['<p>Hi { { client_name }}</p>', ['}}']],
    'a dropped closing brace' => ['<p>Hi {{ client_name }</p>', ['{{']],
    'a typo outside a style block that is fine inside one' => [
        '<style>.a{b:c}}</style><p>Hi {{ client_name }</p>',
        ['{{'],
    ],
    'well formed (control)' => ['<p>Hi {{ client_name }}</p>', []],
]);

it('only strips markup for the brace check, never for the field scan', function (): void {
    // An unknown merge field written inside a `<style>` block is still refused
    // by name: `extract()` does not strip, so the token is still found.
    expect(MergeFields::extract('<style>.a{content:"{{ nonsense }}"}</style>'))
        ->toBe(['nonsense']);
});

it('reads several bodies at once and reports each token once', function (): void {
    // A template is a subject and two bodies, and a token in all three is one
    // problem rather than three.
    expect(MergeFields::extract('{{ a }}', null, '{{ a }} {{ b }}'))->toBe(['a', 'b']);
});

it('substitutes in one pass, so a merged value is never substituted again', function (): void {
    // A person whose directory entry reads `{{ team_name }}` is the case: a
    // `preg_replace` per token walks over what it has already written.
    expect(MergeFields::substitute(
        '{{ a }} then {{ b }}',
        static fn (string $token): string => $token === 'a' ? '{{ b }}' : 'B',
    ))->toBe('{{ b }} then B');
});

it('puts a merged value in literally, back-references and all', function (): void {
    /*
     * `preg_replace` would read `$0` and `\1` in a replacement as references
     * to the match. A client called "A&B" is enough to hit one, and the
     * callback form is what makes this a non-question.
     */
    expect(MergeFields::substitute('{{ a }}', static fn (): string => '$0 \\1 & Sons'))
        ->toBe('$0 \\1 & Sons');
});
