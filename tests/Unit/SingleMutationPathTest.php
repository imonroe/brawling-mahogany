<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Nothing but `AdvanceWorkflow` moves workflow state (PRD §8.3 · issue #68).
 *
 * The Build Plan calls this the architectural keystone and says exactly what
 * it costs to lose:
 *
 * > If a controller ever writes `stages.state` directly, the audit trail, the
 * > automation dispatch, and the gate guarantees all become optional — and
 * > nobody notices until something has been silently skipped.
 *
 * Issue #68's definition of done asks for this test by name: *"proven by a
 * test that asserts no other class writes `stages.state`."*
 *
 * ## Why a source-reading test rather than a runtime one
 *
 * The same reason as `ModelTenancyConventionTest` and
 * `UnscopedQueryConventionTest`. A runtime test can only catch a path it
 * thought to exercise; the bug this guards against is a path nobody thought
 * about — a controller written in Slice 4 that sets `state` because it was
 * quicker than reading this file. Reading the source catches the writer who
 * never ran the test.
 *
 * Slice 1 earned this lesson three times over: a rule enforced at call sites
 * was enforced at *some* call sites, twice in consecutive review rounds. So
 * the rules that matter get a machine.
 *
 * ## What the first version of this test missed
 *
 * Adversarial review found three writes it waved straight through, and the
 * first two are the ones that matter, because neither is exotic:
 *
 *  - `$stage->setAttribute('state', …)` — the identical write, spelled as a
 *    method call. Eloquent's own `fill()` ends up here.
 *  - `$stage->{$column} = …` and `->update([$column => …])` — a column name
 *    held in a variable is still a column name, and the regex was looking for
 *    a literal.
 *  - `DB::table('stages')->update(['state' => …])` — which the test did not
 *    merely fail to match, it never *read* the file: a class that bypasses
 *    Eloquent has no reason to mention `Stage::class`, so it never entered the
 *    candidate set at all.
 *
 * Round 2 then found three more past the widened version — an array-key
 * assignment (`$payload['state'] = …`), a literal dynamic property, and
 * `setRawAttributes()` — which is the argument for the dataset below rather
 * than for a cleverer regex. A guard that only catches the careless spelling
 * of a mistake is worth less than no guard, because it reads as coverage. So
 * every shape is pinned by `it('catches every shape…')`: the detector is run
 * against each bypass, and against innocent code that must stay quiet.
 *
 * ## This test and the runtime hook cover different halves
 *
 * `HasStateMachine`'s `saving` hook holds the transition map on anything that
 * goes through the model's save path. It does **not** see `saveQuietly()`, an
 * Eloquent mass `update()`, or a query-builder write — those skip model events
 * by design. Those three are exactly what a source-reading test can catch and
 * a runtime one cannot, which is why both exist.
 */

/**
 * Ways of writing a state column, as regexes.
 *
 * Deliberately not a general "does this file mention state" check, which would
 * be noise. These are the shapes an actual write takes.
 */
/**
 * The calls that take an array of columns and write them.
 *
 * Interpolated into the array-shaped patterns below so `['state' => …]` counts
 * only when it is an *argument to a write*. Without that, the pattern cannot
 * tell a write from a serialisation — and
 *
 *     Inertia::render('Deals/Show', ['stages' => $workflow->stages
 *         ->map(fn (Stage $s) => ['id' => $s->id, 'state' => $s->state->value])])
 *
 * is what the deal detail screen (#74) and the deals index will both look
 * like. Flagging a read-only prop map would be worse than a miss, because the
 * failure message's own remedy is to add the file to
 * SANCTIONED_STATE_WRITERS — a blanket exemption, for the rest of that
 * controller's life, earned by a read. The guard's remedy would be the thing
 * that turns the guard off in the file most likely to break it later.
 */
const WRITE_CALLS = '(?:fill|forceFill|update|updateOrCreate|firstOrCreate|updateQuietly|insert|insertGetId|create|createQuietly|setRawAttributes|forceCreate)';

/**
 * One level of nesting inside the argument array.
 *
 * `->update(['configuration' => ['a'], 'state' => …])` has a `]` before the
 * key that matters, so a plain `[^\]]*` stops too early.
 */
const ARRAY_BODY = '(?:[^\[\]]|\[(?:[^\[\]]*)\])*';

const STATE_WRITE_PATTERNS = [
    // ->state = StageState::Complete
    '/->\s*state\s*=(?!=)/',
    // ->fill(['state' => …]) and friends — the array shape, but only as an
    // argument to something that writes. See WRITE_CALLS.
    '/'.WRITE_CALLS.'\s*\(\s*\['.ARRAY_BODY.'[\'"]state[\'"]\s*=>/s',
    // ->transitionTo(…), the model's own mechanism
    '/->\s*transitionTo\s*\(/',
    // ->setAttribute('state', …) and ->setAttribute($column, …) — the same
    // write spelled as a method call, and a column name in a variable is
    // still a column name
    '/->\s*setAttribute\s*\(\s*[\'"]state[\'"]/',
    '/->\s*setAttribute\s*\(\s*\$/',
    // $stage->{$column} = … and $stage->{'state'} = … — a dynamic property
    // write is a column write whatever is inside the braces
    '/->\s*\{[^}]*\}\s*=(?!=)/',
    // ->update([$column => …]) — a variable key, again only as an argument to
    // a write, because `[$key => $value]` inside a mapWithKeys is ordinary
    '/'.WRITE_CALLS.'\s*\(\s*\['.ARRAY_BODY.'\$\w+\s*=>/s',
    // $payload['state'] = …; $stage->forceFill($payload) — building the
    // update by key rather than as a literal. Ordinary code, and the `=>`
    // pattern above does not match an `=`.
    '/\[\s*[\'"]state[\'"]\s*\]\s*=(?!=)/',
    // ->setRawAttributes([...], true) — straight past the mutators, the casts
    // and the transition map in one call
    '/->\s*setRawAttributes\s*\(/',
    // DB::table('stages')->update(…) — Eloquent bypassed entirely, so the
    // model, its casts, and its transition map never see the write
    '/DB::\s*table\s*\(\s*[\'"](?:stages|workflows|gates|action_instances)[\'"]\s*\)/',
    // …and the same by hand
    '/\bUPDATE\s+(?:stages|workflows|gates|action_instances)\b/i',

    /*
     * And the override flag, which is workflow state by every argument that
     * makes `stages.state` workflow state — F4.9's override is a flag, an
     * immutable audit entry naming who/when/which gate/why, a timeline marker,
     * and an auto-created follow-up task, and a caller that writes the flag
     * and forgets the other three looks like it worked.
     *
     * Added when #77 gave `overridden` its first writer. Before that the guard
     * covered `stages.state` only, and a probe proved the gap was real: a
     * controller calling `Gate::query()->update(['overridden' => true])`
     * passed this test, while the same controller writing `stages.state`
     * failed it. Documenting that hazard was the alternative; holding it is
     * better.
     */
    '/->\s*overridden\s*=(?!=)/',
    '/'.WRITE_CALLS.'\s*\(\s*\['.ARRAY_BODY.'[\'"]overridden[\'"]\s*=>/s',
    '/->\s*setAttribute\s*\(\s*[\'"]overridden[\'"]/',
    '/\[\s*[\'"]overridden[\'"]\s*\]\s*=(?!=)/',

    /*
     * And `is_met`, which #71-shaped work gave its first writer outside this
     * file's own cache refresh.
     *
     * It belongs here for the reason `overridden` does, and the reason is the
     * *pair*: IA §8 insists **overridden is not a kind of met**, so a caller
     * that could write one of the two columns without the other is exactly the
     * place that distinction starts to drift. `AdvanceWorkflow::confirm()`
     * writes `is_met` and never `overridden`; `override()` writes `overridden`
     * and never `is_met`. Keeping both writers in one file is what makes that
     * sentence checkable rather than remembered.
     *
     * `GateFactory` is sanctioned below on the same grounds as the workflow
     * and stage factories: a suite that could not build a met gate could not
     * test the service that reads one.
     */
    '/->\s*is_met\s*=(?!=)/',
    '/'.WRITE_CALLS.'\s*\(\s*\['.ARRAY_BODY.'[\'"]is_met[\'"]\s*=>/s',
    '/->\s*setAttribute\s*\(\s*[\'"]is_met[\'"]/',
    '/\[\s*[\'"]is_met[\'"]\s*\]\s*=(?!=)/',

    /*
     * `override_reason` and `overridden_by` are deliberately not guarded — the
     * two that exist, and the list is exactly those two. They are the *record*
     * of the decision rather than the decision: neither changes whether a gate
     * blocks an advance, and writing one without `overridden` moves no
     * workflow. `overridden` is the flag both of them describe, so guarding it
     * is guarding the act, and a pattern per column would be three ways to say
     * one thing, two of which fire on a row that is already correct.
     *
     * `stages.skipped_reason` is not guarded either, for a different reason:
     * F4.12's skip is #70's work and nothing writes it yet. It belongs here
     * the day something does.
     */
];

/**
 * Files allowed to write workflow state, each with the reason.
 *
 * @var array<string, string>
 */
const SANCTIONED_STATE_WRITERS = [
    'app/Support/Workflow/AdvanceWorkflow.php' => 'The single mutation path itself (#68). It also '.
        'writes `action_instances.state` in one direction only: skipping a stage cancels what was '.
        'queued for it, because a stage that did not apply to this deal must not have told the '.
        'client that it did (#92).',

    'app/Support/Workflow/InstantiateWorkflow.php' => 'Sets the opening state of a brand-new workflow. '.
        'There is no stage being left, so there is nothing for the gates to evaluate — routing this '.
        'through AdvanceWorkflow would mean inventing a stage-zero for it to complete.',

    'app/Models/Workflow.php' => 'Declares its own transition map; writes nothing.',
    'app/Models/Stage.php' => 'Declares its own transition map; writes nothing.',
    'app/Models/Deal.php' => 'Declares its own transition map; writes nothing. A deal state is not a '.
        'workflow state — closing a deal is F3.8, not an advance.',
    'app/Models/Concerns/HasStateMachine.php' => 'The transition mechanism. It says which moves are '.
        'possible; AdvanceWorkflow decides which are permitted.',

    'database/factories/WorkflowFactory.php' => 'Sets the opening state of a record being created, '.
        'which is the one write no transition map has an opinion about — there is no previous state '.
        'to move from. Factories are also test fixtures: a suite that could not build a workflow in '.
        'a given state could not test the service that moves it out of one.',
    'database/factories/StageFactory.php' => 'The same, one level down.',
    'database/factories/GateFactory.php' => 'The same, for `is_met`: a suite that could not build a '.
        'met gate could not test the service that reads one, or the advance that clears one.',

    /*
     * `action_instances.state`, added in Slice 3 (#92, #93).
     *
     * Four writers, and they are four steps of one path rather than four
     * independent ones — a row is raised, released, carried out, or stopped,
     * and each owns exactly one of those verbs. What the guard is for is the
     * fifth: a controller that writes `pending` because it was quicker than
     * calling `ApproveMessage`, which is the same shape as a controller
     * writing `stages.state` and has a worse consequence.
     */
    'app/Support/Automation/RaiseAutomations.php' => 'Raises the row, and sets the one state no '.
        'transition has an opinion about — there is no previous state to move from. F5.7\'s 30-day '.
        'approval window is applied here precisely so one place decides whether a new instance '.
        'waits for a person. Nothing here moves an instance that already exists.',

    'app/Support/Automation/ApproveMessage.php' => 'The only door out of `awaiting_approval`, and a '.
        'service rather than a controller for F5.7\'s reason: releasing a message is the state, an '.
        'activity entry, an audit entry and a queue dispatch, and a second implementation would '.
        'remember three of the four. It owns `cancelled` too, because stopping a message and '.
        'releasing one are the same decision answered two ways.',

    'app/Support/Automation/ExecuteAction.php' => 'The send path: the only writer of `sent`, and of '.
        'the `failed` a refusal produces. It is where F5.9\'s rails are asked, immediately before '.
        'the transport, which issue #96 requires in as many words — a rail checked anywhere earlier '.
        'is one a message queued five minutes before somebody pulled the cord sails past.',

    'database/factories/ActionInstanceFactory.php' => 'The same argument as the workflow and stage '.
        'factories: a suite that could not build a sent, failed or awaiting-approval instance could '.
        'not test the services that produce one.',
];

/**
 * Strip comments, keep strings.
 *
 * A docblock that *describes* `['state' => …]` is prose, and a test that
 * flagged it would be measuring the explanation rather than the code. Strings
 * stay, because `DB::table('stages')` and a heredoc of raw SQL are exactly
 * what the last two patterns are for.
 */
function codeWithoutComments(string $contents): string
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

/** Does this source write workflow state, by any of the shapes above? */
function writesWorkflowState(string $contents): bool
{
    $code = codeWithoutComments($contents);

    foreach (STATE_WRITE_PATTERNS as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Does this source go anywhere near workflow state?
 *
 * Naming the model is the ordinary way. Naming the *table* is the way somebody
 * gets around the model, so it counts too — that omission is what let
 * `DB::table('stages')` through the first version of this test.
 */
function touchesWorkflowState(string $contents): bool
{
    $code = codeWithoutComments($contents);

    /*
     * `Gate` is in this list, and leaving it out made the `overridden`
     * patterns dead on arrival.
     *
     * This is the **candidate filter** — the write patterns are only ever run
     * against files it admits — so a column added to `STATE_WRITE_PATTERNS`
     * without its model added here is a pattern that can never match. When
     * #77 widened the patterns to `gates.overridden`, the probe that was
     * supposed to prove it worked lived in a controller that already named
     * `Workflow`, so it cleared the filter for an unrelated reason and the
     * verification proved the wrong thing. A class writing nothing but
     * `Gate::query()->update(['overridden' => true])` was still never read.
     *
     * The same shape as the `DB::table('stages')` hole #68's first review
     * found. **Adding a guarded column means adding its model and its table
     * here as well.**
     *
     * `ActionInstance` joined them in Slice 3 (#92, #93), and it is the same
     * argument one table over. `action_instances.state` decides whether an
     * email reaches a client: a caller moving a row from `awaiting_approval`
     * to `pending` has released a message past F5.7's approval queue, which
     * PRD §4.5 calls a **launch blocker, not an enhancement**, and one writing
     * `sent` has told a team a client heard something they did not. Both are
     * one array key, and both look exactly like housekeeping.
     *
     * No new pattern was needed: the column is called `state`, so the shapes
     * `stages.state` already had cover it. What was needed was the filter —
     * `ExecuteAction` and `ApproveMessage` were invisible to this test purely
     * because neither happened to mention `Stage`, which is not a property
     * anybody was maintaining.
     */
    foreach ([
        '/\b(?:Stage|Workflow|Gate|ActionInstance)(?:::|\s*\$)/',
        '/[\'"](?:stages|workflows|gates|action_instances)[\'"]/',
        '/\b(?:UPDATE|INSERT\s+INTO)\s+(?:stages|workflows|gates|action_instances)\b/i',
    ] as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Classes under app/ that touch a workflow-state model or table at all.
 *
 * @return array<string, string> relative path => contents
 */
function filesTouchingWorkflowState(): array
{
    $found = [];

    /*
     * `app/`, and also `routes/` and `database/`.
     *
     * A closure route and a seeder are both places somebody writes a state
     * because it was the quickest way to make a screen look right, and both
     * were invisible to the first version of this test. `database/migrations`
     * is excluded on purpose: a migration writing a column by name is what a
     * migration is, and `stages.state` has to be created and backfilled by
     * something.
     */
    $finder = (new Finder)
        ->files()
        ->in([app_path(), base_path('routes'), base_path('database')])
        ->notPath('migrations')
        ->name('*.php');

    foreach ($finder as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        if (touchesWorkflowState($contents)) {
            $path = str_replace('\\', '/', $file->getRealPath());
            $found[ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/')] = $contents;
        }
    }

    return $found;
}

it('lets nothing but the advance service write workflow state', function (): void {
    $offenders = [];

    foreach (filesTouchingWorkflowState() as $path => $contents) {
        if (array_key_exists($path, SANCTIONED_STATE_WRITERS)) {
            continue;
        }

        if (writesWorkflowState($contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe(
        [],
        'A class outside AdvanceWorkflow writes workflow state. Every advance must go through '
        .'App\Support\Workflow\AdvanceWorkflow so the gates are evaluated, the transition is '
        .'transactional, and the timeline and audit entries are written. Override (#69) and skip '
        .'(#70) are paths *through* that service, not second writers. If this really is a new '
        .'legitimate writer, add it to SANCTIONED_STATE_WRITERS with a reason — and be sure the '
        .'reason is not "it was easier".',
    );
});

/**
 * The guard, guarded.
 *
 * Every entry here is a write that reached `stages.state` past the first
 * version of this test. Pinning them means the next person who widens the
 * patterns cannot narrow them by accident, and it is the only way to say
 * "this catches X" that stays true after somebody edits the regexes.
 */
it('catches every shape of writing workflow state', function (string $shape): void {
    $source = "<?php\n\nclass Sneaky\n{\n    public function run(Stage \$stage): void\n    {\n        {$shape}\n    }\n}\n";

    expect(touchesWorkflowState($source))->toBeTrue("The detector never even reads: {$shape}")
        ->and(writesWorkflowState($source))->toBeTrue("The detector waves through: {$shape}");
})->with([
    'plain property' => ['$stage->state = StageState::Complete;'],
    'array key' => ['$stage->forceFill([\'state\' => StageState::Complete])->save();'],
    'double-quoted key' => ['$stage->update(["state" => StageState::Complete]);'],
    'transition method' => ['$stage->transitionTo(StageState::Complete);'],
    'setAttribute' => ['$stage->setAttribute(\'state\', StageState::Complete);'],
    'variable property' => ['$column = \'state\'; $stage->{$column} = StageState::Complete;'],
    'variable array key' => ['$column = \'state\'; $stage->update([$column => StageState::Complete]);'],
    'query builder' => ['DB::table(\'stages\')->whereKey($stage->id)->update([\'state\' => \'complete\']);'],
    'eloquent mass update' => ['Stage::query()->whereKey($stage->id)->update([\'state\' => \'complete\']);'],
    'raw sql' => ['DB::statement(\'UPDATE stages SET state = \\\'complete\\\'\');'],
    // Round 2 found these three past the widened detector. The first is the
    // one that matters: building an update payload by key is ordinary code.
    'array key assignment' => ['$payload = []; $payload[\'state\'] = \'complete\'; $stage->forceFill($payload)->save();'],
    'literal dynamic property' => ['$stage->{\'state\'} = StageState::Complete;'],
    'setRawAttributes' => ['$stage->setRawAttributes([\'state\' => \'complete\'], true);'],
    // Round 3: the spelling in between two that were already pinned — a
    // variable column name, as a method call. `active → complete` is a legal
    // transition, so HasStateMachine's hook lets it through and this is the
    // only thing standing there.
    'setAttribute with a variable' => ['$column = \'state\'; $stage->setAttribute($column, StageState::Complete);'],
    'nested array' => ['$stage->update([\'configuration\' => [\'a\'], \'state\' => \'complete\']);'],
]);

/**
 * The shapes that carry their own signal, in a fixture that names nothing.
 *
 * The filter admits a file when it sees the **model** or the **table**. These
 * three carry the table or the model inside the write itself, so they clear it
 * unaided — and the fixture deliberately has no type hint, no import and no
 * mention of a gate anywhere else, so nothing but the shape can be doing it.
 *
 * That is the half round 1's fix got wrong twice. The first attempt at these
 * cases used `run(Gate $gate)`, which matches the filter's *model* pattern on
 * the parameter type — so `touchesWorkflowState()` was true by the signature,
 * exactly as `run(Stage $stage)` had been in the older dataset, and exactly
 * what its docblock claimed to have escaped. A fixture that cannot fail the
 * filter cannot test it.
 */
it('reads a file whose only signal is the write itself', function (string $shape): void {
    $source = "<?php\n\nclass Sneaky\n{\n    public function run(\$id): void\n    {\n        {$shape}\n    }\n}\n";

    expect(touchesWorkflowState($source))->toBeTrue("The detector never even reads: {$shape}")
        ->and(writesWorkflowState($source))->toBeTrue("The detector waves through: {$shape}");
})->with([
    // The override flag, which is what this dataset was added for.
    'gate, eloquent mass update' => ['Gate::query()->whereKey($id)->update([\'overridden\' => true]);'],
    /*
     * A static call that is not `::query` or `::class`, which is the whole
     * point of the model pattern taking a bare `::`. Every other case here
     * spells it `::query`, so the alternative the pattern was *widened* to
     * accept was the one alternative no case exercised — reverting it to
     * `(?:::class|\s*\$|::query)` left the suite green.
     */
    'gate, find then update' => ['Gate::findOrFail($id)->update([\'overridden\' => true]);'],
    'gate, query builder' => ['DB::table(\'gates\')->whereKey($id)->update([\'overridden\' => true]);'],
    'gate, raw sql' => ['DB::statement(\'UPDATE gates SET overridden = true\');'],

    /*
     * And the met flag, added with the confirmation route. It is guarded for
     * the *pair*: `overridden` alone would let a controller write `is_met`
     * directly, and IA §8's insistence that overridden is not a kind of met
     * survives only while one file writes both.
     */
    'gate met, eloquent mass update' => ['Gate::query()->whereKey($id)->update([\'is_met\' => true]);'],
    'gate met, find then update' => ['Gate::findOrFail($id)->update([\'is_met\' => true]);'],
    'gate met, query builder' => ['DB::table(\'gates\')->whereKey($id)->update([\'is_met\' => true]);'],
    'gate met, raw sql' => ['DB::statement(\'UPDATE gates SET is_met = true\');'],

    /*
     * And `stages.state`, the column this whole test was built for — whose
     * table patterns turned out to be just as unheld, for longer.
     *
     * `catches every shape` covers these three shapes already, but through
     * `run(Stage $stage)`: the *model* half of the filter is satisfied by the
     * parameter, so narrowing both table patterns to `gates` alone left the
     * entire suite green. That is the `DB::table('stages')` hole #68's first
     * review found, re-opened and invisible. A query-builder write is exactly
     * the case that names no model — it is what "Eloquent bypassed entirely"
     * means — so it is the case that most needs a fixture naming none.
     */
    'stage, eloquent mass update' => ['Stage::query()->whereKey($id)->update([\'state\' => \'complete\']);'],
    'stage, query builder' => ['DB::table(\'stages\')->whereKey($id)->update([\'state\' => \'complete\']);'],
    'stage, raw sql' => ['DB::statement(\'UPDATE stages SET state = \\\'complete\\\'\');'],
    /*
     * A static call on the model, which is what holds each alternative of the
     * filter's *model* pattern. `Workflow` had only table shapes here, so
     * dropping it from that pattern failed nothing.
     */
    'workflow, eloquent mass update' => ['Workflow::query()->whereKey($id)->update([\'state\' => \'completed\']);'],
    'workflow, query builder' => ['DB::table(\'workflows\')->whereKey($id)->update([\'state\' => \'completed\']);'],
    'workflow, raw sql' => ['DB::statement(\'UPDATE workflows SET state = \\\'completed\\\'\');'],
]);

/**
 * And the shapes that need the file to have named a gate somewhere.
 *
 * `$gate->overridden = true;` carries no signal of its own: the variable could
 * be anything. The filter cannot read every file in `app/` — that is the point
 * of having a filter — so these are held only against a file that names the
 * model: a type hint, or any static call on it (`::query`, `::find`,
 * `::findOrFail`, `::class`).
 *
 * **Not a relation.** `foreach ($deal->workflows as $w)` down to a gate names
 * no guarded model anywhere, and the filter does not see it. An earlier
 * version of this paragraph claimed relations were covered; they are not, and
 * `Gate::findOrFail($id)->update([...])` was not covered either until the
 * model pattern stopped insisting on `::query` or `::class` specifically.
 *
 * **So this asserts the pattern half only.** Asserting the filter half here
 * would be asserting the type hint, which is the trap above. The limit is
 * real and worth stating rather than papering over: a write to `overridden`
 * on an untyped variable, in a file that names neither `Gate` nor `gates`, is
 * invisible to this test. Nothing in the codebase looks like that, and the
 * runtime half of the guarantee — `HasStateMachine`'s `saving` hook — does not
 * care what the file mentions.
 */
it('matches every shape of writing the override flag', function (string $shape): void {
    $source = "<?php\n\nclass Sneaky\n{\n    public function run(Gate \$gate): void\n    {\n        {$shape}\n    }\n}\n";

    expect(writesWorkflowState($source))->toBeTrue("The detector waves through: {$shape}");
})->with([
    'plain property' => ['$gate->overridden = true;'],
    'array key' => ['$gate->forceFill([\'overridden\' => true])->save();'],
    'double-quoted key' => ['$gate->update(["overridden" => true]);'],
    'setAttribute' => ['$gate->setAttribute(\'overridden\', true);'],
    'array key assignment' => ['$payload = []; $payload[\'overridden\'] = true; $gate->forceFill($payload)->save();'],
    'met, plain property' => ['$gate->is_met = true;'],
    'met, array key' => ['$gate->forceFill([\'is_met\' => true])->save();'],
    'met, double-quoted key' => ['$gate->update(["is_met" => true]);'],
    'met, setAttribute' => ['$gate->setAttribute(\'is_met\', true);'],
    'met, array key assignment' => ['$payload = []; $payload[\'is_met\'] = true; $gate->forceFill($payload)->save();'],
]);

it('stays quiet about code that only reads workflow state', function (string $shape): void {
    $source = "<?php\n\nclass Innocent\n{\n    public function run(Stage \$stage): void\n    {\n        {$shape}\n    }\n}\n";

    expect(writesWorkflowState($source))->toBeFalse("The detector cries wolf over: {$shape}");
})->with([
    'reading' => ['$label = $stage->state->label();'],
    'comparing' => ['if ($stage->state === StageState::Complete) { return; }'],
    'querying' => ['Stage::query()->where(\'state\', StageState::Active)->get();'],
    'counting' => ['$count = Stage::query()->whereIn(\'state\', [StageState::Active])->count();'],
    /*
     * Round 3 found these two flagged, and both are what #74 looks like.
     *
     * A false positive is worse than a miss here, because the failure
     * message's remedy is a whole-file exemption: the guard would be switched
     * off in the deal controller by the act of rendering a stage rail.
     */
    'serialising a prop' => [
        '$props = $stage->workflow->stages->map(fn (Stage $s) => [\'id\' => $s->id, \'state\' => $s->state->value])->all();',
    ],
    'keying a collection' => ['$byId = $stages->mapWithKeys(fn (Stage $s) => [$key => $s->name]);'],
]);

it('keeps the advance service free of HTTP concerns', function (): void {
    // F12.5: "the API layer should be designed so a native client can be added
    // without rework." This is the service that would otherwise accrete
    // request handling, and a service that reads a session cannot be called
    // from a queue, a webhook, or a test without one.
    $source = (string) file_get_contents(app_path('Support/Workflow/AdvanceWorkflow.php'));

    foreach (['Illuminate\\Http\\Request', 'Inertia\\', 'session(', 'request(', 'auth()'] as $forbidden) {
        expect($source)->not->toContain(
            $forbidden,
            "AdvanceWorkflow references {$forbidden}. It takes a workflow and an actor and returns "
            .'a result object; the controller adapts (F12.5).',
        );
    }
});

it('names every sanctioned writer as a file that exists', function (): void {
    foreach (array_keys(SANCTIONED_STATE_WRITERS) as $path) {
        expect(file_exists(base_path($path)))->toBeTrue(
            "{$path} is listed as a sanctioned state writer and no longer exists. Remove the entry — "
            .'a stale allow-list reads as coverage it does not have.',
        );
    }
});
