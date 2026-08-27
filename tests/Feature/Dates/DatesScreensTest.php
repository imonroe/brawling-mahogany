<?php

declare(strict_types=1);

use App\Enums\OffsetBasis;
use App\Models\Deal;
use App\Models\KeyDate;
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
