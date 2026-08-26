<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Enums\PersonLifecycleState;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Queries\ActivityFeed;
use App\Support\Activity\RecordActivity;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * S12 and S26 across the tenant boundary (PRD §8.2 · ADR 0002 · issue #81).
 *
 * The feed is the widest read in the product: one screen showing every kind of
 * event a team has produced. A gap here would not leak one record, it would
 * leak the whole timeline — so it gets its own file rather than a case
 * appended to somebody else's.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    [$this->otherTeam, $this->otherMember] = $this->teamWithMember();
});

function isolationDeal(Team $team): Deal
{
    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
    ]));
}

it('never shows one team another team’s activity', function (): void {
    app(TeamContext::class)->runFor($this->otherTeam, fn () => app(RecordActivity::class)->record(
        subject: $this->otherMember,
        eventType: 'contact.logged',
        summary: 'Phone call about 4 Privet Drive',
        source: ActivitySource::Manual,
    ));

    // The row exists — otherwise this test passes on an empty table, which is
    // exactly what a broken scope would also produce.
    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 0));
});

it('refuses to attach a contact to another team’s deal', function (): void {
    $theirDeal = isolationDeal($this->otherTeam);

    $this->actingAsPerson($this->member, $this->team);

    $membership = $this->member->membershipIn($this->team);

    $this->post("/people/{$membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'deal_id' => $theirDeal->getKey(),
    ])->assertSessionHasErrors('deal_id');

    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('never offers another team’s people to the shell’s modal', function (): void {
    $theirMembership = $this->otherMember->membershipIn($this->otherTeam);

    app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn () => $theirMembership?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $mine = $this->member->membershipIn($this->team);

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $mine?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $this->actingAsPerson($this->member, $this->team);

    $response = $this->getJson('/people/candidates?q=Zebediah')->assertOk();

    /*
     * Both teams now hold a Zebediah, which is what stops this asserting on an
     * empty list. A broken endpoint returning nothing at all would pass "no
     * foreign candidates" and fail here.
     */
    expect($response->json('candidates'))->toHaveCount(1)
        ->and($response->json('candidates.0.id'))->toBe($mine?->getKey());
});

it('never leaks a deal name through the feed’s deal label', function (): void {
    // The one column #81 added, exercised the way a bug would exercise it: an
    // event in team A whose `deal_id` names a deal in team B. The composite
    // foreign key refuses the row outright, which is ADR 0002 layer 2 doing
    // the work a scope alone could not.
    $theirDeal = isolationDeal($this->otherTeam);

    app(TeamContext::class)->runFor($this->team, function () use ($theirDeal): void {
        expect(fn () => ActivityEvent::factory()->create([
            'team_id' => $this->team->getKey(),
            'subject_type' => (new Person)->getMorphClass(),
            'subject_id' => $this->member->getKey(),
            'deal_id' => $theirDeal->getKey(),
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('never shows a person-subjected event to somebody who may not see people', function (): void {
    /*
     * The **other** axis this file exists for: not the wrong team, the wrong
     * colleague.
     *
     * A person-subjected event is a contact log (F2.5) — a client's name, and
     * a free-text note about what was said. `/activity` gates on `people.view`
     * at the **screen**, and that covered it for exactly as long as the feed
     * had one caller. S10's dashboard panel reuses the same query behind a
     * `deals.view` gate, so a composed *"deals but not the client directory"*
     * role got the client's full name and the note on the screen they land on,
     * with a link to a person page that answers 403.
     *
     * `ActivityFeed::query()`'s own docblock predicted it — *"the next surface
     * the feed reaches into needs its own rule … a subject type with no rule
     * is visible to everyone who can open the feed"* — and there was a rule
     * for deals and one for properties and none for people.
     *
     * So the rule lives in the query rather than in either screen: a filter
     * written into a caller is a filter the next caller is written without,
     * which is exactly what happened.
     */
    $client = null;

    app(TeamContext::class)->runFor($this->team, function () use (&$client): void {
        $client = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Marguerite',
            'last_name' => 'Vanterpool',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        // Subjected to the **person**, which is what `ContactLogController`
        // does — F2.5 logs a contact against a person, and the membership is
        // how the feed resolves the name to show.
        app(RecordActivity::class)->record(
            subject: $client->person,
            eventType: 'contact.logged',
            summary: 'Discussed her budget and the second mortgage',
            source: ActivitySource::Manual,
        );
    });

    $narrow = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($narrow): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $narrow->getKey(),
            'first_name' => 'Dana',
            'last_name' => 'Alvarez',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'deals_only',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                Permissions::VIEW_DEALS,
                Permissions::MANAGE_DEALS,
            ])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($narrow, $this->team);

    // The control: they *can* open the dashboard, so an absent name below is
    // the filter working rather than the page refusing.
    $dashboard = $this->get('/dashboard')->assertOk();

    expect($dashboard->getContent())->not->toContain('Vanterpool')
        ->and($dashboard->getContent())->not->toContain('second mortgage');

    // And the screen whose gate used to be the only thing protecting it still
    // refuses them outright, which is the behaviour that must not change.
    $this->get('/activity')->assertForbidden();
});

/**
 * The pattern the subject scan runs, in **one** place.
 *
 * It was written out twice — once in the scan and once in the probes that
 * prove the scan behaves — which is this branch's own recurring finding
 * applied to the guard on the guard: edit the scan's copy and the probes go on
 * passing, against the pattern they no longer describe.
 *
 * A property chain, and **never a method call**. `subject:` is
 * `RecordActivity`'s named argument and it is always handed a model —
 * `$deal`, `$membership->person`.
 *
 * The trailing lookahead is what keeps a chain whole, and all three forbidden
 * characters earn their place — the engine backtracks off the end of a
 * rejected call and matches a shorter prefix of the same chain otherwise.
 * Without `-`, `$template->channel->hasSubject()` came back as `Channel`;
 * without `\w`, it came back as `HasSubjec`. Forbidding all three means the
 * pattern matches a whole chain or nothing at all.
 *
 * That is safe rather than a hole: a relation *call* returns a builder, not a
 * model, so it is never something `RecordActivity` could be given.
 *
 * ## Which `subject:` this is, though, is not the pattern's job
 *
 * `subject:` is no longer only `RecordActivity`'s. Slice 3 added mailables,
 * and `new Envelope(subject: $subject)` is the plainest possible Laravel — so
 * a scan that reads every `subject:` in `app/` reported an email's subject
 * line as an activity subject type, twice, with two different names as the
 * expression behind it changed.
 *
 * Narrowing the *expression* is what the first two attempts did, and it
 * cannot work: `subject: $subject` and `subject: $deal` are the same shape.
 * The thing that tells them apart is what is being **called**, so
 * `activitySubjectsIn()` below decides that structurally and this pattern only
 * reads the expression once the call is known. An unrecognised `subject:`
 * fails the build rather than being guessed at — the same fail-closed
 * direction as the allowlist it guards.
 */
const ACTIVITY_SUBJECT_PATTERN = '/^\s*\$([A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*)(?![\w(\-])/';

/**
 * Named arguments called `subject` that are **not** an activity subject.
 *
 * An allowlist of constructors rather than an exclusion of expressions, which
 * is the correction this scan needed twice. `new Envelope(subject: $subject)`
 * and `subject: $deal` are the same shape, so nothing about the *expression*
 * can separate them — what separates them is what is being called.
 *
 * A constructor not on this list fails the build rather than being guessed at.
 *
 * @var list<string>
 */
const NON_ACTIVITY_SUBJECT_CALLS = [
    // A mail subject line (Slice 3's mailables).
    'Envelope',
    // `RenderedMessage`'s own subject — the words, not who they are about.
    'RenderedMessage',
];

/**
 * The source as the scanner should see it: code, with the prose taken out.
 *
 * `SingleMutationPathTest` does the same thing for the same reason, and this
 * scan needed it the moment it started failing closed: several files in `app/`
 * *describe* `subject:` in a docblock — including the docblock explaining this
 * very rule — and a guard that reads its own documentation as a violation is
 * a guard nobody can satisfy.
 *
 * Strings are kept. Nothing here matches inside one today, and dropping them
 * would be the kind of narrowing that quietly stops finding things.
 */
function activityScanSource(string $contents): string
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * Every `subject:` in one file, classified by the call it belongs to.
 *
 * Structural rather than a cleverer regex: each occurrence is read backwards
 * to whatever opened the argument list.
 *
 *  - `->record(subject: …)` — `RecordActivity`. The expression is returned.
 *  - `new X(subject: …)` where X is in {@see NON_ACTIVITY_SUBJECT_CALLS} — a
 *    subject *line*, not a subject *type*. Ignored.
 *
 * Anything else lands in `unrecognised`, and the caller fails the build on it.
 * That is deliberate and it is the whole reason this is not an exclusion list:
 * a third kind of `subject:` in Slice 5 stops the build the day it is written
 * rather than being silently read as a subject type or silently dropped.
 *
 * @return array{subjects: list<string>, unrecognised: list<string>}
 */
function activitySubjectsIn(string $source): array
{
    $subjects = [];
    $unrecognised = [];

    preg_match_all('/\bsubject:/', $source, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as [$_, $offset]) {
        $before = substr($source, 0, $offset);
        $after = substr($source, $offset + strlen('subject:'));

        /*
         * Read backwards past the arguments already in this call and look at
         * what opened it. Two levels of nested parentheses are tolerated in
         * those earlier arguments — `replyTo: $this->replyToAddresses(),` is
         * one, and a call site that nests deeper lands in `unrecognised`
         * rather than being skipped, which is the fail-*closed* direction.
         */
        $opener = preg_match(
            '/(?:->\s*(record)|new\s+([A-Za-z_][A-Za-z0-9_]*))\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*$/s',
            $before,
            $call,
        ) === 1 ? $call : null;

        if ($opener === null) {
            $unrecognised[] = trim(substr($before, max(0, strlen($before) - 60)).'subject:');

            continue;
        }

        if (($opener[2] ?? '') !== '') {
            if (in_array($opener[2], NON_ACTIVITY_SUBJECT_CALLS, true)) {
                continue;
            }

            $unrecognised[] = 'new '.$opener[2].'(… subject:';

            continue;
        }

        if (preg_match(ACTIVITY_SUBJECT_PATTERN, $after, $expression) === 1) {
            $subjects[] = $expression[1];
        }
    }

    return ['subjects' => $subjects, 'unrecognised' => $unrecognised];
}

/**
 * The shape probes for the scan above.
 *
 * `SingleMutationPathTest`'s rule, applied here: *"a pattern added without one
 * passes immediately and looks exactly like a pattern that works."* The
 * pattern was narrowed in Slice 3 so an email's `subject:` argument stopped
 * reading as an activity subject, and a narrowing is exactly the change that
 * can quietly stop matching the thing it exists to find.
 *
 * The first four are the real call sites' shapes. The last three are the
 * shapes that must **not** match, each of which the first two attempts at the
 * narrowing got wrong.
 */
it('matches an activity subject and never a method call', function (string $source, ?string $expected): void {
    $found = activitySubjectsIn($source);

    expect($found['unrecognised'])->toBe([], 'A `subject:` shape the scan cannot classify.')
        ->and($found['subjects'][0] ?? null)->toBe($expected);
})->with([
    'a bare model' => ['$this->activity->record(subject: $deal,)', 'deal'],
    'a relation' => ['$this->activity->record(subject: $membership->person,)', 'membership->person'],
    'last argument' => ['$this->activity->record(subject: $workflow)', 'workflow'],
    'two deep' => ['$this->activity->record(subject: $stage->workflow->deal,)', 'stage->workflow->deal'],
    /*
     * Not the first argument. The whole reason this is structural rather than
     * a `->record(\s*subject:` regex: a call site that puts the actor first is
     * ordinary PHP, and a scan that only ever looked immediately after the
     * paren would drop its subject silently — which is the fail-open direction
     * this file exists to refuse.
     */
    'after another argument' => ['$this->activity->record(actor: $person, subject: $deal)', 'deal'],

    /*
     * A method call is still never a subject, whatever the call it sits in.
     */
    'a method call' => ['$x->record(subject: $template->channel->hasSubject() ? 1 : 2,)', null],
    'a method call on the variable itself' => ['$x->record(subject: $rendered->subject(),)', null],
    'a bare call' => ['$x->record(subject: $value(),)', null],

    /*
     * The shape that started all this. `new Envelope(subject: $subject)` is
     * the plainest Laravel there is, and it is not an activity subject — it
     * is recognised and ignored rather than narrowed against, because
     * `subject: $subject` and `subject: $deal` are the same shape and no
     * expression-level pattern can tell them apart.
     */
    'a mail envelope' => ['return new Envelope(subject: $subject,);', null],
    'a mail envelope after another argument' => [
        'return new Envelope(replyTo: $to, subject: $subject);',
        null,
    ],
]);

it('fails on a `subject:` it cannot classify', function (): void {
    /*
     * The probe for the fail-closed half, which is the half a passing suite
     * cannot otherwise demonstrate. A third kind of `subject:` — a value
     * object constructed directly, say — must stop the build rather than be
     * quietly read as a subject type or quietly dropped.
     */
    $found = activitySubjectsIn('$thing = new SomethingElse(subject: $deal);');

    expect($found['subjects'])->toBe([])
        ->and($found['unrecognised'])->toHaveCount(1);
});

it('gives every subject type the feed carries a permission rule', function (): void {
    /*
     * The guard on the guard, and the reason the filter is an **allowlist**.
     *
     * It was three `!=` rules with a docblock warning that *"a subject type
     * with no rule is visible to everyone who can open the feed"* — and the
     * warning came true, twice: the person rule was missing outright, and the
     * dashboard then reused the query behind a different screen gate. An
     * exclusion list fails open, which is the lesson ADR 0002 already records
     * from the purge cascade.
     *
     * So this reads every `subject:` argument in `app/` and fails when one
     * names a class `subjectPermissions()` does not. A fifth subject type in a
     * later slice is invisible until somebody gives it a rule — and this test
     * says so at the moment it is added, rather than a reviewer noticing.
     */
    $sources = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => activityScanSource((string) file_get_contents($file->getPathname())));

    $found = activitySubjectsIn($sources->implode("\n"));

    /*
     * An unclassifiable `subject:` fails here rather than being skipped. The
     * alternative is the shape ADR 0002 keeps recording: a list that fails
     * open, quietly, on exactly the case nobody thought about.
     */
    expect($found['unrecognised'])->toBe([], sprintf(
        'A `subject:` argument in app/ belongs to neither RecordActivity nor a mail envelope, '
        .'so the scan cannot say whether it names an activity subject type: %s. '
        .'Teach activitySubjectsIn() about the call it belongs to.',
        implode(' · ', $found['unrecognised']),
    ));

    $subjects = collect($found['subjects'])->unique()->values();

    // The scan has to be finding things: a pattern that quietly stopped
    // matching would make the assertion below pass over an empty list.
    expect($subjects->count())->toBeGreaterThanOrEqual(4);

    /*
     * `$membership->person` and `$link->deal` resolve to the same four classes
     * as the bare variables, so the check is on the tail of each expression.
     */
    /*
     * No exclusion list. `Str::afterLast` already takes the tail of
     * `$participant->deal` and `$link->deal`, so both resolve to `Deal` — an
     * earlier version carried a `reject(['Participant', 'Link'])` that could
     * never fire, which is a fail-open branch inside a fail-closed guard and
     * exactly the shape this test exists to catch one layer down.
     */
    $resolved = $subjects
        ->map(fn (string $expression): string => (string) Str::afterLast($expression, '>'))
        ->map(fn (string $name): string => Str::studly(Str::singular($name)))
        ->unique()
        ->values();

    $named = collect(ActivityFeed::subjectPermissions())
        ->keys()
        ->map(fn (string $morph): string => class_basename($morph));

    $missing = $resolved->reject(fn (string $class): bool => $named->contains($class));

    expect($missing->all())->toBe([], sprintf(
        'These subject types have no permission rule in ActivityFeed::subjectPermissions(), '
        .'so the feed would either hide them from everyone or show them to anyone: %s',
        $missing->implode(', '),
    ));
});

it('never shows a deal’s name on a client page to somebody who may not see deals', function (): void {
    /*
     * The sibling of the case above, on the screen that does **not** go
     * through `ActivityFeed::query()`.
     *
     * S31 builds its own `forSubject($person)` query with its own limit, so it
     * inherited none of the per-viewer rules — and F2.5 logs a contact against
     * a person and *optionally* a deal, so a `people.view`-only reader was
     * shown the deal the contact was attached to and a link to a page
     * answering 403.
     *
     * That is why the rules are `visibleToViewer()` rather than lines inside
     * `query()`: three callers apply them now, and the sentence this branch
     * has proved twice is that a filter written into one caller is a filter
     * the next caller is written without.
     *
     * Written because the fix shipped without it. Reverting
     * `PersonController` to its previous form left the whole suite green,
     * which is the same silence that let the original leak through.
     */
    $client = null;
    $deal = null;

    app(TeamContext::class)->runFor($this->team, function () use (&$client, &$deal): void {
        $deal = Deal::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => 'Ravenscroft Sale',
            'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
        ]);

        $client = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Imogen',
            'last_name' => 'Ravenscroft',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        app(RecordActivity::class)->record(
            subject: $client->person,
            eventType: 'contact.logged',
            summary: 'Talked through the survey',
            source: ActivitySource::Manual,
            deal: $deal,
        );
    });

    $directoryOnly = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($directoryOnly): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $directoryOnly->getKey(),
            'first_name' => 'Rowan',
            'last_name' => 'Ellis',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'directory_only_feed',
            'name' => 'Directory Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                Permissions::VIEW_PEOPLE,
                Permissions::MANAGE_PEOPLE,
            ])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($directoryOnly, $this->team);

    $response = $this->get("/people/{$client->getKey()}")->assertOk();

    $activity = $response->viewData('page')['props']['activity'];

    /*
     * The **control**, and it is what stops this passing vacuously: a full
     * viewer sees the row, so an empty list below would be the filter working
     * rather than the fixture failing to produce anything.
     */
    $this->actingAsPerson($this->member, $this->team);

    $full = $this->get("/people/{$client->getKey()}")->assertOk();

    expect($full->viewData('page')['props']['activity'])->toHaveCount(1)
        ->and($full->viewData('page')['props']['activity'][0]['deal']['label'])
        ->toBe('Ravenscroft Sale');

    // And the narrow reader gets no deal-context row at all.
    expect($activity)->toBe([]);
});
