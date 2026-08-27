<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Enums\OffsetBasis;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\MessageTemplate;
use Inertia\Testing\AssertableInertia;

/**
 * S18 and S59 — Dates & Deadlines (PRD §4.8 F8.2 · IA §2 · issue #107).
 *
 * IA §11's naming rule is asserted here as well as the behaviour, because a
 * screen whose heading says "Key dates" is a screen that has quietly reverted
 * the decision — and it is the kind of regression a reviewer reads past.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('adds a date and derives one from it', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/dates", [
        'name' => 'Mutual acceptance',
        'mode' => 'typed',
        'date' => '2026-09-01',
    ])->assertRedirect();

    $anchor = KeyDate::query()->sole();

    $this->post("/deals/{$this->deal->getKey()}/dates", [
        'name' => 'Inspection objection',
        'mode' => 'derived',
        'anchorKeyDateId' => $anchor->getKey(),
        'offsetDays' => 10,
        'offsetBasis' => OffsetBasis::Calendar->value,
    ])->assertRedirect();

    $derived = KeyDate::query()->where('name', 'Inspection objection')->sole();

    expect($derived->date->toDateString())->toBe('2026-09-11')
        ->and($derived->is_derived)->toBeTrue();
});

it('shows why a derived date is what it is', function (): void {
    $anchor = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    KeyDate::factory()->derivedFrom($anchor, 10)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
    ]);

    $this->get("/deals/{$this->deal->getKey()}/dates")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $dates = collect($page->toArray()['props']['dates']);

            $derived = $dates->firstWhere('name', 'Inspection objection');

            // #107: *"derived dates show their anchor and offset, so a user
            // can see **why** a date is what it is."*
            expect($derived['derivation'])->toBe('10 calendar days after Mutual acceptance')
                ->and($derived['isDerived'])->toBeTrue();
        });
});

it('says a detached date used to follow its anchor', function (): void {
    $anchor = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    $derived = KeyDate::factory()->derivedFrom($anchor, 10)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
    ]);

    $this->patch("/deals/{$this->deal->getKey()}/dates/{$derived->getKey()}", [
        'name' => 'Inspection objection',
        'mode' => 'typed',
        'date' => '2026-09-20',
    ])->assertRedirect();

    $this->get("/deals/{$this->deal->getKey()}/dates")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['dates'])
                ->firstWhere('name', 'Inspection objection');

            expect($row['isDerived'])->toBeFalse()
                ->and($row['wasDetached'])->toBeTrue()
                ->and($row['derivation'])->toContain('until somebody set it by hand');
        });
});

it('previews the cascade without applying it', function (): void {
    $anchor = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    KeyDate::factory()->derivedFrom($anchor, 10)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
    ]);

    $response = $this->postJson("/deals/{$this->deal->getKey()}/dates/preview", [
        'keyDateId' => $anchor->getKey(),
        'name' => 'Mutual acceptance',
        'mode' => 'typed',
        'date' => '2026-09-04',
    ])->assertOk();

    expect($response->json('moved'))->toHaveCount(1)
        ->and($response->json('moved.0.to'))->toBe('2026-09-14')
        // Nothing was written: a preview with a side effect is not a preview.
        ->and($anchor->refresh()->date->toDateString())->toBe('2026-09-01');
});

it('refuses an anchor that loops, with a sentence somebody can read', function (): void {
    $first = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => '2026-09-01',
    ]);

    $second = KeyDate::factory()->derivedFrom($first, 10)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
    ]);

    $this->patch("/deals/{$this->deal->getKey()}/dates/{$first->getKey()}", [
        'name' => 'Mutual acceptance',
        'mode' => 'derived',
        'anchorKeyDateId' => $second->getKey(),
        'offsetDays' => 1,
        'offsetBasis' => OffsetBasis::Calendar->value,
    ])->assertSessionHasErrors('anchorKeyDateId');
});

it('refuses an anchor on another deal', function (): void {
    $other = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $elsewhere = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $other->getKey(),
    ]);

    /*
     * A composite foreign key already refuses another **team's** row, and
     * nothing in the database refuses another *deal's* — both are the same
     * team's `key_dates`. A cascade across two unrelated transactions is what
     * that would build.
     */
    $this->post("/deals/{$this->deal->getKey()}/dates", [
        'name' => 'Closing',
        'mode' => 'derived',
        'anchorKeyDateId' => $elsewhere->getKey(),
        'offsetDays' => 3,
        'offsetBasis' => OffsetBasis::Calendar->value,
    ])->assertSessionHasErrors('anchorKeyDateId');
});

it('lists the next fourteen days across every deal, and counts them', function (): void {
    $other = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Soon',
        'date' => now()->addDays(3)->toDateString(),
    ]);

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $other->getKey(),
        'name' => 'Later',
        'date' => now()->addDays(40)->toDateString(),
    ]);

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Missed',
        'date' => now()->subDays(2)->toDateString(),
    ]);

    $this->get('/dates')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect(collect($props['dates'])->pluck('name')->all())->toBe(['Soon'])
                ->and($props['counts']['upcoming'])->toBe(1)
                ->and($props['counts']['overdue'])->toBe(1);
        });

    $this->get('/dates?window=overdue')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['dates']);

            expect($rows->pluck('name')->all())->toBe(['Missed'])
                ->and($rows->first()['isPastDue'])->toBeTrue();
        });
});

it('leaves an unconfirmed extracted date out of every count', function (): void {
    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Suggested closing',
        'date' => now()->addDays(3)->toDateString(),
    ]);

    // Shown on the deal's own tab, so somebody can agree to it…
    $this->get("/deals/{$this->deal->getKey()}/dates")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['dates']);

            expect($rows)->toHaveCount(1)
                ->and($rows->first()['isPending'])->toBeTrue();
        });

    // …and counted nowhere until they have.
    $this->get('/dates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dates', [])
            ->where('counts.upcoming', 0));
});

it('lets a date carry its own reminder schedule, and tells the screen it is stored', function (): void {
    /*
     * `reminder_offsets` had a request rule, a writer and a reader, and no
     * screen sent it — `CLAUDE.md`'s *"a row nothing can reach"*, with the help
     * article telling people they could change and disable reminders. This is
     * the path, end to end.
     *
     * The `remindersAreSet` flag is the load-bearing half: `reminderDays()`
     * resolves the default, so a form reading only that would write today's
     * default onto every row somebody opened and saved — and the date would
     * stop following the rule the moment it was marked critical.
     */
    $this->post("/deals/{$this->deal->getKey()}/dates", [
        'name' => 'Financing contingency',
        'mode' => 'typed',
        'date' => now()->addDays(30)->toDateString(),
    ])->assertRedirect();

    $date = KeyDate::query()->sole();

    expect($date->reminder_offsets)->toBeNull()
        ->and($date->reminderDays())->toBe([7, 1]);

    $this->patch("/deals/{$this->deal->getKey()}/dates/{$date->getKey()}", [
        'name' => 'Financing contingency',
        'mode' => 'typed',
        'date' => $date->date->toDateString(),
        'reminderOffsets' => [14, 7, 1],
    ])->assertRedirect();

    expect($date->fresh()->reminderDays())->toBe([14, 7, 1]);

    $this->get("/deals/{$this->deal->getKey()}/dates")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['dates'])->first();

            expect($row['reminderDays'])->toBe([14, 7, 1])
                ->and($row['remindersAreSet'])->toBeTrue();
        });

    // And off is a real answer, distinct from "use the default".
    $this->patch("/deals/{$this->deal->getKey()}/dates/{$date->getKey()}", [
        'name' => 'Financing contingency',
        'mode' => 'typed',
        'date' => $date->date->toDateString(),
        'reminderOffsets' => [],
    ])->assertRedirect();

    expect($date->fresh()->reminder_offsets)->toBe([])
        ->and($date->fresh()->reminderDays())->toBe([]);
});

it('counts critical dates inside the window the tab is showing', function (): void {
    /*
     * The toggle narrows the current list, so its count has to be *"of what
     * you are looking at, how many are critical"*. It counted *"critical and
     * still ahead"* regardless of the window, so on Past due a checkbox
     * reading `(0)` produced three rows — and three overdue critical
     * deadlines is precisely the state S59 exists to surface.
     */
    foreach ([-9, -4, -1] as $index => $offset) {
        KeyDate::factory()->create([
            'team_id' => $this->team->getKey(),
            'deal_id' => $this->deal->getKey(),
            'name' => 'Missed deadline '.$index,
            'date' => now()->addDays($offset)->toDateString(),
            'is_critical' => true,
        ]);
    }

    // Well past the fourteen-day horizon, so Next 14 days must not count it.
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => now()->addDays(60)->toDateString(),
        'is_critical' => true,
    ]);

    $this->get('/dates?window=overdue')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('dates', 3)
            ->where('counts.critical', 3));

    $this->get('/dates?window=overdue&critical=1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('dates', 3));

    // And the other direction: nothing critical inside the next fortnight.
    $this->get('/dates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dates', [])
            ->where('counts.critical', 0));
});

it('does not spend another route’s rate limit', function (): void {
    /*
     * For a signed-in person `ThrottleRequests` keys on the **person's id
     * alone** — no route, no name — so every inline `throttle:n,m` in the app
     * incremented one counter and each middleware compared it against its own
     * maximum. The effective limit on any route was the tightest number among
     * every throttled route that person had touched in the last minute.
     *
     * The cascade preview is the loudest thing in that bucket and the one
     * route written to be pressed on a keystroke, at 120 a minute. Twelve
     * presses of it refused the first-ever *Send a test*, whose own limit is
     * ten and which the person had not pressed once.
     */
    $date = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Mutual acceptance',
        'date' => now()->addDays(10)->toDateString(),
    ]);

    foreach (range(1, 12) as $ignored) {
        $this->postJson("/deals/{$this->deal->getKey()}/dates/preview", [
            'keyDateId' => $date->getKey(),
            'name' => 'Mutual acceptance',
            'mode' => 'typed',
            'date' => now()->addDays(11)->toDateString(),
        ])->assertOk();
    }

    /*
     * A different route with a limit of ten, never pressed. Any answer but 429
     * proves the buckets are separate — this asserts on the status rather than
     * on the body, because what the endpoint *does* is another test's subject.
     */
    $template = MessageTemplate::factory()->create(['team_id' => $this->team->getKey()]);

    expect($this->post("/templates/messages/{$template->getKey()}/test")->getStatusCode())
        ->not->toBe(429);
});

it('refuses an impossible reminder schedule in words, under the field’s own key', function (): void {
    /*
     * Laravel keys a `reminderOffsets.*` failure as `reminderOffsets.0`, and
     * S18 renders `errors.reminderOffsets` — so a refused schedule showed
     * **nothing**: the field kept the typed value, the save did not happen,
     * and the dialog gave no reason. The message it could not show was the raw
     * *"The reminderOffsets.0 field must be between 0 and 90."*
     *
     * Fixed at both ends: sentences here, and a dialog that renders whichever
     * key the failure arrived under.
     */
    $date = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => now()->addDays(30)->toDateString(),
    ]);

    $edit = fn (array $offsets) => $this->patch(
        "/deals/{$this->deal->getKey()}/dates/{$date->getKey()}",
        [
            'name' => 'Closing',
            'mode' => 'typed',
            'date' => $date->date->toDateString(),
            'reminderOffsets' => $offsets,
        ],
    );

    $edit([120])->assertSessionHasErrors();

    expect(collect(session('errors')->getBag('default')->all())->implode(' '))
        ->toContain('90 days')
        ->not->toContain('reminderOffsets');

    $edit([1, 2, 3, 4, 5, 6, 7])->assertSessionHasErrors('reminderOffsets');

    expect(session('errors')->first('reminderOffsets'))->toContain('Six');

    // And the row is untouched by either refusal.
    expect($date->fresh()->reminder_offsets)->toBeNull();
});

it('drops a closed deal from the cross-deal list and its counts, and keeps it on the deal', function (): void {
    /*
     * The reminder sweep has always read `Deal::open()`; S59, its count badge
     * and the calendar grid did not. So the Overdue tab accumulated every past
     * deadline of every deal the team had ever closed, growing without bound,
     * on the screen Screen Inventory calls the one an agent checks to see the
     * week's exposure — while the emails about those same dates had stopped
     * months before. Three readers of one table, two rules.
     *
     * The second half is the part worth keeping: a closed deal's **own** tab
     * still lists its dates. That is the record of what happened, and somebody
     * looking at a closed deal is looking at it on purpose.
     */
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => now()->subDays(40)->toDateString(),
    ]);

    $this->get('/dates?window=overdue')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('dates', 1)
            ->where('counts.overdue', 1));

    $this->deal->forceFill(['state' => DealState::Closed, 'closed_at' => now()])->save();

    $this->get('/dates?window=overdue')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dates', [])
            ->where('counts.overdue', 0));

    $this->get("/deals/{$this->deal->getKey()}/dates")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('dates', 1));
});

it('calls it Dates & Deadlines, never Key dates', function (): void {
    /*
     * IA §2 and §11. Emily's exact phrase, and the kind of decision that gets
     * quietly reverted by a screen somebody wrote from the column name.
     */
    /*
     * The **template**, not the file. A docblock explaining the rule
     * necessarily quotes the banned phrase, and a scan that read it would be
     * measuring the explanation rather than the screen — the same reason
     * `SingleMutationPathTest` strips comments before matching.
     */
    $markup = function (string $page): string {
        $source = (string) file_get_contents(resource_path("js/pages/{$page}.vue"));

        $start = mb_strpos($source, '<template>');

        expect($start)->not->toBeFalse();

        return preg_replace('/<!--.*?-->/s', '', mb_substr($source, (int) $start)) ?? '';
    };

    foreach (['Deals/Dates', 'Dates/Index'] as $page) {
        $template = $markup($page);

        expect($template)->toContain('Dates &amp; Deadlines')
            ->and($template)->not->toContain('Key dates')
            ->and($template)->not->toContain('Key Dates')
            ->and($template)->not->toContain('Important dates');
    }
});

it('refuses a read to somebody who cannot see deals', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $stranger = $this->actingAsPerson(null, $otherTeam);

    expect($stranger)->not->toBeNull();

    $this->get("/deals/{$this->deal->getKey()}/dates")->assertNotFound();
});
