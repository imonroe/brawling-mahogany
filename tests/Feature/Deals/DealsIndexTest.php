<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Enums\ParticipantRole;
use App\Enums\SystemRole;
use App\Enums\WorkflowState;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Queries\DealDirectory;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S13 — the deals index (PRD §4.9 F9.1 · issue #78).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

function indexDeal(DealState $state = DealState::Active, array $attributes = []): Deal
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
        'state' => $state,
        ...$attributes,
    ]));
}

it('renders the index with no deals at all', function (): void {
    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Index')
            ->where('segment', 'open')
            ->has('deals.data', 0));
});

/**
 * #78: *"Closed deals are excluded by default."*
 *
 * PRD §9 sizes a team at twenty-five active and several hundred closed, so the
 * default view being everything is the screen being useless on the install it
 * was sized for.
 */
it('shows open deals by default and hides the closed ones', function (): void {
    $open = indexDeal();
    indexDeal(DealState::Closed);
    indexDeal(DealState::Nurture);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $open->getKey()));

    // And they are reachable, rather than gone.
    $this->get('/deals?segment=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('deals.data', 3));
});

/**
 * A team whose deals are all closed has deals.
 *
 * The default segment is `open`, so they land on an empty list — and "No deals
 * yet. Create your first deal." is what a screen says to somebody with none.
 * The `all` count is on the page and knows better.
 */
it('does not tell a team with closed deals that it has none', function (): void {
    indexDeal(DealState::Closed);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $counts = collect($props['segmentCounts'])->keyBy('value');

            // Nothing in the default view, and yet plainly not a new team.
            expect($props['deals']['data'])->toHaveCount(0)
                ->and($counts['all']['count'])->toBe(1);
        });
});

it('counts every segment, not only the one being shown', function (): void {
    indexDeal();
    indexDeal();
    indexDeal(DealState::Closed);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $counts = collect($page->toArray()['props']['segmentCounts'])->keyBy('value');

            expect($counts['open']['count'])->toBe(2)
                ->and($counts['all']['count'])->toBe(3)
                ->and($counts['closed']['count'])->toBe(1);
        });
});

it('falls back to open rather than emptying the screen on a nonsense segment', function (): void {
    indexDeal();

    $this->get('/deals?segment=banana')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('segment', 'open')
            ->has('deals.data', 1));
});

it('searches the typed name and the generated one', function (): void {
    /*
     * **Both halves of both rows are pinned**, including the ones the
     * assertions never mention.
     *
     * `DealFactory` defaults `generated_name` to `faker->streetAddress()`, and
     * the typed row leaving it unset is a coin flip: the full suite drew an
     * *Elmer* street for it, `search=elm` matched two rows, and the file
     * passed on its own all day. A fixture is only pinned when the fields the
     * query reads are pinned, not the fields the assertion reads.
     */
    $typed = indexDeal(attributes: ['name' => 'Bosart purchase', 'generated_name' => '2 Quarry Way']);
    $generated = indexDeal(attributes: ['name' => null, 'generated_name' => '14 Elm St']);

    $this->get('/deals?search=bosart')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $typed->getKey()));

    // The other half: `displayName()` falls back to the generated name, so
    // searching only `name` would miss whichever half a deal is shown by.
    $this->get('/deals?search=elm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $generated->getKey()));
});

it('escapes the wildcards, so a search for a percent sign is a search', function (): void {
    // Pinned for the reason the search test above gives: the query reads both
    // columns, so a faker street left in either one is an unpinned fixture.
    indexDeal(attributes: ['name' => 'Seller wants 100% of list', 'generated_name' => '2 Quarry Way']);
    indexDeal(attributes: ['name' => 'Ordinary deal', 'generated_name' => '3 Quarry Way']);

    $this->get('/deals?search='.urlencode('100%'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('deals.data', 1));
});

/**
 * The client cell, resolved the way `DealHeader` resolves it.
 *
 * The main contact when somebody has said which, otherwise the first
 * participant — so the index and the deal it opens name the same human.
 */
it('names the primary participant in the client cell', function (): void {
    $deal = indexDeal();

    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Emily',
            'last_name' => 'Bosart',
        ]);

        DealParticipant::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'team_membership_id' => $membership->getKey(),
            'participant_role' => ParticipantRole::Buyer,
            'is_primary' => true,
        ]);
    });

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('deals.data.0.client', 'Emily Bosart'));
});

/**
 * PRD §7.5 gives a deal concurrent workflows on purpose, and one cell cannot
 * name two stages.
 *
 * Settled the way `DealHeader::advanceTarget()` settled the Advance button:
 * only when exactly one is running. A cell that silently picked one would be
 * wrong half the time and never say so.
 */
it('names the stage only when one workflow is running', function (): void {
    $deal = indexDeal();

    $first = app(TeamContext::class)->runFor($this->team, function () use ($deal): Workflow {
        $workflow = Workflow::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'state' => WorkflowState::Active,
        ]);

        $stage = Stage::factory()->active()->create([
            'team_id' => $this->team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'name' => 'Inspection',
            'sort_order' => 0,
        ]);

        $workflow->forceFill(['current_stage_id' => $stage->getKey()])->save();

        return $workflow;
    });

    expect($first)->toBeInstanceOf(Workflow::class);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('deals.data.0.stage', 'Inspection'));

    // A second running workflow, and the cell goes quiet rather than guessing.
    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        $workflow = Workflow::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'state' => WorkflowState::Active,
        ]);

        $stage = Stage::factory()->active()->create([
            'team_id' => $this->team->getKey(),
            'workflow_id' => $workflow->getKey(),
            'name' => 'Pre-listing works',
            'sort_order' => 0,
        ]);

        $workflow->forceFill(['current_stage_id' => $stage->getKey()])->save();
    });

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('deals.data.0.stage', null));
});

/**
 * The "next date" cell is the soonest **open** task.
 *
 * `key_dates` is S18 in Slice 4. A task due date is a real date somebody has
 * to act by, which is the nearest true answer the schema can give today — and
 * a completed task's date is not one of them.
 */
it('shows the soonest open task due date, and ignores the finished ones', function (): void {
    $deal = indexDeal();

    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'due_date' => now()->addDays(9)->toDateString(),
        ]);

        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        // Overdue and done: not something coming up.
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'due_date' => now()->subDays(30)->toDateString(),
            'completed_at' => now()->subDays(29),
        ]);
    });

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deals.data.0.nextDate', now()->addDays(3)->toDateString()));
});

/**
 * Sorted by the string the cell shows, which is neither column on its own.
 *
 * `displayName()` prefers the typed `name` and falls back to
 * `generated_name`, so a mixed list is the only fixture that can see the
 * difference. The first version of this test set `name => null` on both rows
 * — which made every row's displayed string *equal* its `generated_name`, so
 * ordering by that column passed while the screen alphabetised by a value the
 * reader could not see.
 */
it('sorts by the name the row displays, both ways', function (): void {
    indexDeal(attributes: ['name' => 'Aardvark typed', 'generated_name' => '9 Zoo Lane']);
    indexDeal(attributes: ['name' => null, 'generated_name' => 'Middle Road']);
    indexDeal(attributes: ['name' => 'Zebra typed', 'generated_name' => '1 Aardvark Ave']);

    $this->get('/deals?sort=primary&direction=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deals.data.0.name', 'Aardvark typed')
            ->where('deals.data.2.name', 'Zebra typed'));

    $this->get('/deals?sort=primary&direction=desc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deals.data.0.name', 'Zebra typed')
            ->where('deals.data.2.name', 'Aardvark typed'));
});

/**
 * A blank name is not a name, and `coalesce` alone disagrees.
 *
 * `displayName()` falls back on **blank** — it `trim()`s the typed name and
 * moves on if nothing is left — while `coalesce` falls back on **null**. So a
 * `name` of `''` is a value `coalesce` keeps and sorts by, and the row
 * alphabetises under an empty string while displaying its generated name.
 *
 * Nothing writes a blank name today: `DealDraft::text()` normalises it and
 * `CreateDealFromDraft` is the only writer. But `name` is fillable, and the
 * first rename endpoint that trusts `nullable|string` reintroduces it — which
 * is the whole reason the sort expression, not the writer, is where this is
 * settled.
 *
 * **A tab is blank too, and that is the half `btrim()` alone gets wrong.**
 * `btrim(x)` strips spaces and nothing else, so a `name` of `"\t"` survived
 * as a value and — under this database's `C` collation, where 0x09 precedes
 * every letter — headed an ascending list while the row showed its generated
 * name. The expression names PHP `trim()`'s character set for that reason,
 * and this fixture is what fails when somebody shortens it back.
 */
it('treats a blank name the way the row does, as no name', function (): void {
    $spaces = indexDeal(attributes: ['name' => 'placeholder', 'generated_name' => 'ZZZ generated']);
    $tabbed = indexDeal(attributes: ['name' => 'placeholder', 'generated_name' => 'YYY generated']);
    $nulled = indexDeal(attributes: ['name' => null, 'generated_name' => 'MMM generated']);

    // Straight to the column, because every writer normalises these away.
    DB::table('deals')->where('id', $spaces->getKey())->update(['name' => '   ']);
    DB::table('deals')->where('id', $tabbed->getKey())->update(['name' => "\t\x0B"]);

    $this->get('/deals?sort=primary&direction=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // M, Y, Z — every row sorting by what it shows.
            ->where('deals.data.0.id', $nulled->getKey())
            ->where('deals.data.1.id', $tabbed->getKey())
            ->where('deals.data.2.id', $spaces->getKey()));
});

/**
 * A deal with no name at all sorts last, both ways.
 *
 * `generated_name` is nullable and `name` may be too — a deal with neither a
 * subject property nor a named client has nothing to sort by. Postgres
 * defaults to NULLS FIRST on a descending sort, so without `nulls last` the
 * nameless deal would head the list on one of the two presses.
 */
it('sorts a nameless deal last however the arrow points', function (): void {
    indexDeal(attributes: ['name' => 'Has a name', 'generated_name' => null]);
    $nameless = indexDeal(attributes: ['name' => null, 'generated_name' => null]);

    foreach (['asc', 'desc'] as $direction) {
        $this->get("/deals?sort=primary&direction={$direction}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('deals.data.1.id', $nameless->getKey()));
    }
});

/**
 * Every column the table offers to sort is a column the server sorts by.
 *
 * These are two vocabularies that were never introduced. `dealRow.ts` names
 * the deal column `primary`; `DealDirectory::SORTS` said `name`. `Table`
 * emits `column.key`, the page puts it straight into the query string, and
 * the allowlist rejected it — so pressing **Deal** fell to the default
 * ordering, and the branch that exists for hand-typed junk swallowed the
 * app's own button. `date` worked only because both lists happen to spell it
 * the same way.
 *
 * Read out of the TypeScript rather than restated here, because a copy of the
 * list is a third vocabulary. Adding `sortable: true` to a column now fails
 * the build until the server can sort by it.
 */
it('sorts by every column key the table offers', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/components/app/dealRow.ts'));

    /*
     * Commentary out first, and for the floor's sake rather than the scan's.
     *
     * The floor below counts `sortable: true` marks in the source. A file
     * whose docblock *mentions* `sortable: true` — this one's own type
     * definition is one sentence away from doing so — would count a mark with
     * no entry behind it, and fail a correct `dealRow.ts` for describing
     * itself. `routeTargets.test.ts` strips for the mirror-image reason.
     */
    $source = (string) preg_replace(
        ['#/\\*[\\s\\S]*?\\*/#', '#(^|[^:])//[^\n]*#m'],
        [' ', '$1'],
        $source,
    );

    // The `key: '…'` of every entry carrying `sortable: true`.
    preg_match_all("/\\{\\s*key:\\s*'([a-z0-9]+)'[^}]*sortable:\\s*true/i", $source, $matches);

    $offered = $matches[1];

    /*
     * The floor counts the *marks*, not a number.
     *
     * The pattern requires `key:` before `sortable:` inside an entry, so
     * `{ sortable: true, key: 'meta1', … }` — same meaning, properties the
     * other way round — is invisible to it. A hard `toHaveCount(2)` did not
     * cover that: it passes at two found while three are marked, and it also
     * fails a legitimate third sortable column added properly to both lists.
     *
     * Counting `sortable: true` separately catches the reordering (three
     * marked, two found) and lets a real third column through.
     */
    expect($offered)->toHaveCount(preg_match_all('/\\bsortable:\\s*true/', $source))
        ->and($offered)->toEqualCanonicalizing(DealDirectory::SORTS);
});

/**
 * The date sort, end to end — the key the UI actually sends for that column,
 * and the branch whose `nulls last` nothing was checking.
 *
 * A deal with nothing due sorts last either way round: an empty cell is not
 * "soonest", and it is not "latest" either. Postgres defaults to NULLS FIRST
 * on a descending sort, so without the clause the empty one heads the list on
 * one of the two presses.
 */
it('sorts by the next date, with the empty ones last either way', function (): void {
    $soon = indexDeal(attributes: ['name' => 'Due soon']);
    $later = indexDeal(attributes: ['name' => 'Due later']);
    $never = indexDeal(attributes: ['name' => 'Nothing due']);

    app(TeamContext::class)->runFor($this->team, function () use ($soon, $later): void {
        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $soon->getKey(),
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        Task::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $later->getKey(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    });

    $this->get('/deals?sort=date&direction=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deals.data.0.id', $soon->getKey())
            ->where('deals.data.2.id', $never->getKey()));

    $this->get('/deals?sort=date&direction=desc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deals.data.0.id', $later->getKey())
            // Still last. This is the assertion the `nulls last` exists for.
            ->where('deals.data.2.id', $never->getKey()));
});

/**
 * A junk deal-type filters neither the rows nor the counts.
 *
 * These are two callers of one builder, and the rule went into the second
 * only: `dealType` was resolved *after* `segmentCounts()`, so the counts
 * filtered by the junk value while the rows filtered by the cleaned one. A
 * `?dealType=banana` URL listed deals under a filter bar reading "All (0)" —
 * the exact thing `DealDirectory`'s docblock says sharing a builder prevents.
 *
 * The page reads that `all` count to decide whether it is empty or merely
 * filtered, so on a team whose deals are all closed it brought back "No deals
 * yet. Create your first deal." over a team with deals.
 */
it('ignores a junk deal type in the counts as well as the rows', function (): void {
    indexDeal();
    indexDeal();
    indexDeal(DealState::Closed);

    $this->get('/deals?dealType=banana')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $counts = collect($props['segmentCounts'])->keyBy('value');

            expect($props['deals']['data'])->toHaveCount(2)
                ->and($props['dealType'])->toBe('all')
                // The half that was wrong: counts computed from the raw value.
                ->and($counts['open']['count'])->toBe(2)
                ->and($counts['all']['count'])->toBe(3)
                ->and($counts['closed']['count'])->toBe(1);
        });
});

/**
 * A junk sort key is not echoed back, because the header draws itself from it.
 *
 * `Table` tints a chevron and sets `aria-sort` when `sort` equals a column's
 * key. Echoing an unvalidated key lit the Deal header, flipped its arrow on a
 * second press, and announced "ascending" over rows the server had left in
 * `opened_at` order — a control confirming an action nobody performed.
 */
it('does not echo back a sort key it refused to use', function (): void {
    indexDeal();

    $this->get('/deals?sort=primary%20--&direction=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sort', ''));
});

/**
 * A sort column nobody offers is not a sort column.
 *
 * `orderBy($request->input('sort'))` is an injection hole and a typo is a 500
 * rather than a screen, so `DealDirectory::SORTS` is an allowlist — and
 * anything outside it falls to the default rather than erroring.
 */
it('ignores a sort column that is not on the allowlist', function (): void {
    indexDeal();

    $this->get('/deals?sort=deleted_at+--&direction=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('deals.data', 1));
});

it('refuses somebody who cannot view deals', function (): void {
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Read',
            'last_name' => 'Only',
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', SystemRole::Contact->value)->sole()->getKey(),
        );
    });

    $this->actingAsPerson($person, $this->team);

    $this->get('/deals')->assertForbidden();
});

/**
 * The tiebreaker, asserted against the source rather than against two pages.
 *
 * `PropertiesTest` carries the same guard and says why at length: the outcome
 * is stable in a small fixture by accident of the query plan, so no assertion
 * about *results* can fail while that accident holds. What can fail is the
 * query.
 *
 * Deals tie more readily than properties. `opened_at` is a date two deals
 * created in one sitting share, and `generated_name` is nullable — a deal with
 * neither a subject property nor a named client has nothing to sort by at all.
 */
it('orders by something unique, so a page cannot repeat a row', function (): void {
    $method = new ReflectionMethod(DealDirectory::class, 'applySort');

    $source = implode('', array_slice(
        (array) file((string) $method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    // Comments stripped, so the guard cannot pass on a method that only
    // *mentions* the tiebreaker in a note somebody left behind.
    $source = implode('', array_map(
        fn (array|string $token): string => is_array($token)
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
        token_get_all('<?php '.$source),
    ));

    $tiebreaker = strpos($source, "->orderBy('deals.id')");

    expect($tiebreaker)->not->toBeFalse('the directory query has no unique tiebreaker');

    /*
     * And nothing orders after it.
     *
     * The first version looped over three call spellings and skipped the one
     * the tiebreaker itself uses — so an `->orderBy('deals.updated_at')` added
     * *after* it stayed green, which is the exact regression the check is for.
     * Every ordering call is found instead, and the last one has to be the
     * tiebreaker.
     */
    preg_match_all(
        // `latest`, `oldest` and `inRandomOrder` order without saying "order".
        '/->(?:order[A-Za-z]*|latest|oldest|inRandomOrder)\(/',
        $source,
        $matches,
        PREG_OFFSET_CAPTURE,
    );

    $offsets = array_column($matches[0], 1);

    expect($offsets)->not->toBeEmpty()
        ->and(max($offsets))->toBe($tiebreaker, 'something sorts after the tiebreaker');
});

/**
 * A deal with a property on it still renders — which it did not.
 *
 * `paginate()` eager-loaded `propertyLinks` with
 * `property:id,street,city,state,postal_code,parcel_number`, and `properties`
 * has no `state` column. It has `state_code`; `state` is what `deals` calls
 * its lifecycle. So the moment any deal in the page had a property linked to
 * it, the whole index answered *SQLSTATE[42703]: column "state" does not
 * exist* — a 500 on the primary list screen, for the ordinary case of a deal
 * with a subject property.
 *
 * Five review rounds went past it because **no index fixture ever attached a
 * property to a deal**. Every test here seeded participants, workflows,
 * stages and tasks — the four cells the row renders — and a relation nothing
 * renders is a relation nothing thought to seed. The eager-load is gone now
 * (`row()` never read it), so this is a regression test for the shape rather
 * than for the column list.
 */
it('renders a deal that has a property linked to it', function (): void {
    $deal = indexDeal(attributes: ['name' => 'Has a property']);

    app(TeamContext::class)->runFor($this->team, function () use ($deal): void {
        DealProperty::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $deal->getKey(),
            'property_id' => Property::factory()->create([
                'team_id' => $this->team->getKey(),
            ])->getKey(),
            'is_subject' => true,
        ]);
    });

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $deal->getKey()));
});
