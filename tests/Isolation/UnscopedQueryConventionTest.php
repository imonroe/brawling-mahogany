<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * The escape hatch, counted by a machine rather than by a sentence.
 *
 * ADR 0002's case for layer 1 rests on `withoutTeamScope()` being rare and
 * deliberate. The document said it had *"exactly two callers"*, then three.
 * It had thirteen. Prose lost that argument three rounds running, and the
 * commit that took the count from eleven to thirteen was editing a different
 * paragraph of the same file at the time.
 *
 * So the count lives here. Every call site is listed with a reason, and a
 * fourteenth fails the build until somebody writes down which kind it is.
 *
 * There are only two kinds that are allowed to exist:
 *
 *  1. **A question about the actor.** Which teams am I in? Am I the last owner
 *     of any of them? Is two-factor mandatory for me? These are cross-team by
 *     nature — scoping them to the current team is what makes them wrong, and
 *     round 3 fixed a bug that was exactly that mistake.
 *  2. **A context with no tenant.** The super-admin console runs above teams,
 *     console commands iterate them explicitly, and accepting an invitation
 *     has no team until the token establishes one.
 *
 * Nothing else. An unscoped query that reads *tenant data* — somebody's deals,
 * their people, their documents — is the leak this whole document exists to
 * prevent, and no reason written in this file makes one acceptable.
 */

/**
 * Both spellings of lifting the scope.
 *
 * `withoutTeamScope()` is the sanctioned one and exists to be greppable. But
 * it is a convenience over `withoutGlobalScope(TeamScope::class)`, which any
 * model can call directly — so counting only the convenience would leave the
 * whole convention one keystroke from being bypassed, silently, by somebody
 * who never read this file. Counting both is what makes it a rule.
 */
const UNSCOPED_PATTERN = '/withoutTeamScope\s*\(|withoutGlobalScope\s*\(\s*[\\\\\w]*\bTeamScope::class/';

/**
 * Every sanctioned call site, as `relative/path.php` => why.
 *
 * The line number is deliberately not part of the key: pinning it would make
 * this test fail on every unrelated edit, and the thing worth noticing is a
 * *new file* reaching for the hatch, or a known one reaching for it more often
 * than it says.
 *
 * @var array<string, array{count: int, reason: string}>
 */
const SANCTIONED_UNSCOPED_QUERIES = [
    'Models/Concerns/BelongsToTeam.php' => [
        // Two: the method's own name, and the `withoutGlobalScope` call it
        // wraps. This is the only file where the second spelling belongs.
        'count' => 2,
        'reason' => 'The definition itself.',
    ],

    'Models/Person.php' => [
        // Three, down from four: `identityIsEditableBy()` went with the shared
        // identity columns it guarded (#140).
        'count' => 3,
        'reason' => 'Questions about the actor, all three. Which teams may I act in '.
            '(activeTeams), which membership am I holding here (membershipIn), and drop '.
            'every membership I hold when my account goes (revokeEveryMembership). A '.
            'login spans teams by design, so asking about one inside a single team is '.
            'the bug, not the fix.',
    ],

    'Support/TwoFactorMandate.php' => [
        'count' => 1,
        'reason' => 'PRD §9 makes 2FA mandatory for a Team Owner of *any* team. Asking '.
            'only about the current one would let an owner drop the mandate by '.
            'switching teams first.',
    ],

    'Actions/Teams/RevokeMembership.php' => [
        'count' => 2,
        'reason' => 'The last-owner rule is asked of every team a person owns, and is '.
            'also asked from /settings/profile where the team being asked about is not '.
            'the one resolved. Round 3 fixed both of these being scoped.',
    ],

    'Http/Controllers/Admin/TeamController.php' => [
        'count' => 3,
        'reason' => 'The super-admin console runs above the tenant boundary (ADR 0002), '.
            'behind the super-admin middleware, and every action it takes is audited.',
    ],

    'Http/Controllers/Admin/ImpersonationController.php' => [
        'count' => 2,
        'reason' => 'Same console. Impersonation additionally records a typed reason, '.
            'the team, the person, and when it ended.',
    ],

    'Http/Controllers/Teams/InvitationController.php' => [
        'count' => 1,
        'reason' => 'Accepting an invitation has no team context by definition — the '.
            'hashed single-use token is what establishes one.',
    ],
];

/**
 * Count the real calls in a file, ignoring the ones written about.
 *
 * `TeamScope`'s own docblock explains what `withoutGlobalScope(TeamScope::class)`
 * is for, and a test that counted that would be measuring prose. Comments and
 * strings are stripped through the tokeniser rather than by a cleverer regex,
 * because a cleverer regex is how this gets wrong again.
 */
function unscopedQueriesIn(string $contents): int
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return preg_match_all(UNSCOPED_PATTERN, $code);
}

it('has no unscoped query outside the sanctioned call sites', function (): void {
    $found = [];

    foreach ((new Finder)->files()->in([app_path()])->name('*.php') as $file) {
        $matches = unscopedQueriesIn((string) file_get_contents($file->getRealPath()));

        if ($matches > 0) {
            $found[str_replace('\\', '/', $file->getRelativePathname())] = $matches;
        }
    }

    $unsanctioned = array_diff_key($found, SANCTIONED_UNSCOPED_QUERIES);

    expect($unsanctioned)->toBe(
        [],
        'A new file calls withoutTeamScope(). Add it to SANCTIONED_UNSCOPED_QUERIES with '.
        'a reason, and make sure the reason is one of the two kinds this file names — a '.
        'question about the actor, or a context with no tenant. If it is neither, the '.
        'query is reading tenant data unscoped and the fix is to scope it.',
    );
});

it('counts the unscoped queries in each sanctioned file', function (string $path, array $entry): void {
    $actual = unscopedQueriesIn((string) file_get_contents(app_path($path)));

    expect($actual)->toBe(
        $entry['count'],
        "{$path} now has {$actual} unscoped queries, not {$entry['count']}. If the new one ".
        'is legitimate, raise the count and widen the reason. The reason is the point: '.
        $entry['reason'],
    );
})->with(array_map(
    fn (string $path): array => [$path, SANCTIONED_UNSCOPED_QUERIES[$path]],
    array_keys(SANCTIONED_UNSCOPED_QUERIES),
));

it('finds every sanctioned file still on disk', function (): void {
    foreach (array_keys(SANCTIONED_UNSCOPED_QUERIES) as $path) {
        expect(file_exists(app_path($path)))->toBeTrue(
            "{$path} is listed as an unscoped call site and no longer exists. Remove the ".
            'entry — a stale allow-list reads as coverage it does not have.',
        );
    }
});
