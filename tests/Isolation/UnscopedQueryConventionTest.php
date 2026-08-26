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

    'Models/ActionDefinition.php' => [
        'count' => 1,
        'reason' => 'Reading the message template this automation points at, to refuse an '.
            'archived one or a channel mismatch. Scoped, it answers "is this visible to '.
            'whoever happens to be in context" rather than "what is this row pointing '.
            'at" — and the callers the hook exists for (#92 instantiation, a pack '.
            'install) are exactly the ones running under another team\'s context or '.
            'none, where it returned null and skipped both checks. Reading tenant data '.
            'unscoped is safe here only because the composite foreign key and the CHECK '.
            'constraint already guarantee the template belongs to this row\'s team.',
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

    'Http/Controllers/Settings/ProfileController.php' => [
        // Down from two: the collision check moved inside `runFor`, where the
        // ordinary scope is the right one. Only the question that genuinely
        // spans teams is left unscoped.
        'count' => 1,
        'reason' => 'A question about the actor: which of my own memberships were '.
            'carrying my old sign-in address. It spans every team I am in by '.
            'definition — scoping it to the resolved team would leave the others '.
            'showing an address that stopped working. The *write* that follows is '.
            'scoped again with runFor, because lifting a read out of the scope does '.
            'not lift the BelongsToTeam updating guard with it, and round 2 found '.
            'exactly that 500.',
    ],

    'Support/Teams/InvitationConflict.php' => [
        'count' => 1,
        'reason' => 'A context with no tenant. It is asked at accept time, before a '.
            'token has established a team, and the team it is asked about comes from '.
            'the invitation rather than from the session. Scoped to that team by hand, '.
            'in the query, which is the only shape a no-tenant context can use.',
    ],

    'Support/Workflow/InstantiateWorkflow.php' => [
        'count' => 1,
        'reason' => 'The deal being instantiated names its own team, and the question '.
            'is whether the people a caller nominated for the template roles are on '.
            'that team. It runs before any team is resolved — #74 will call it from a '.
            'controller, but the service is also called from a queue and from tests — '.
            'so it scopes to the deal\'s team explicitly, in the query.',
    ],

    'Http/Controllers/Admin/TeamController.php' => [
        // Five, up from three: the pending-invitation list and the link the
        // console issues for one (ADR 0003). Both name the team explicitly in
        // the query, from a route that already carries it.
        'count' => 5,
        'reason' => 'The super-admin console runs above the tenant boundary (ADR 0002), '.
            'behind the super-admin middleware, and every action it takes is audited.',
    ],

    'Http/Controllers/Admin/ImpersonationController.php' => [
        'count' => 2,
        'reason' => 'Same console. Impersonation additionally records a typed reason, '.
            'the team, the person, and when it ended.',
    ],

    'Actions/Teams/AcceptInvitation.php' => [
        'count' => 1,
        'reason' => 'Accepting an invitation has no team context either — the token '.
            'names the team, and the membership this looks for is the one that team '.
            'already holds for the address. It is scoped to that team by hand, in the '.
            'query, which is the only shape a no-tenant context can use.',
    ],

    'Support/Teams/PendingInvitations.php' => [
        'count' => 1,
        'reason' => 'A question about the actor, and the case that makes the phrase literal: '.
            'which teams have invited *me*. The person asking has no membership anywhere — '.
            'that is the situation — so there is no team to scope to, and the invitation is '.
            'what would establish one. Keyed on their own folded sign-in address, which is '.
            'the same comparison AcceptInvitation and the unique index make.',
    ],

    'Console/Commands/IssueInvitationLink.php' => [
        'count' => 1,
        'reason' => 'A context with no tenant. A console run has no session and therefore no '.
            'resolved team, and the whole question is which team invited an address. --team '.
            'narrows it by hand, in the query, which is the only shape a no-tenant context '.
            'can use.',
    ],

    'Http/Controllers/Teams/InvitationController.php' => [
        'count' => 1,
        'reason' => 'Accepting an invitation has no team context by definition — the '.
            'hashed single-use token is what establishes one.',
    ],

    'Console/Commands/DispatchDueAutomations.php' => [
        'count' => 1,
        'reason' => 'A context with no tenant, and the sweep shape PurgeSoftDeletedRecords '.
            'already uses: a scheduled run has no session, and the question is which '.
            'automation instances are due across every team at once. Nothing is read from '.
            'the row but its id and its team_id, and the job it dispatches re-establishes '.
            'that team before touching anything — RunsForTeam throws rather than running '.
            'unscoped, which is what makes the hand-off safe.',
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
