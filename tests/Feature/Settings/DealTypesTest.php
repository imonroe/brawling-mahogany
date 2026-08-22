<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\DealState;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\DealType;
use App\Support\Tenancy\ArchivedReferenceException;

/**
 * S76 — deal types (issue #58 · PRD §4.3 F3.1, §7.6).
 *
 * The definition of done is *"CRUD with the in-use guard"* and *"seeded
 * defaults present"*. The seeded half is `ReferenceDataTest`'s; this is the
 * screen, and the case worth the most tests is the one the Screen Inventory
 * calls out by name — **the in-use warning**, which exists because a lookup is
 * something other rows point at.
 */
beforeEach(function (): void {
    // `settings.manage` is a Team Owner permission, and PRD §9 makes 2FA
    // mandatory for that role — an un-enrolled owner meets the enrolment
    // screen and every assertion about a page becomes a 302.
    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);

    $this->seed(Database\Seeders\DealTypeSeeder::class);
});

it('shows the system defaults and the team’s own together', function (): void {
    $own = DealType::factory()->create([
        'team_id' => $this->team->getKey(),
        'name' => 'Land Sale',
        'side' => DealSide::Sell,
    ]);

    $this->get('/settings/deal-types')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/DealTypes')
            ->has('dealTypes', 4)
            // Defaults first, then the team's own — the order the picker will
            // use, which is why they are one list rather than two cards.
            ->where('dealTypes.0.isSystem', true)
            ->where('dealTypes.3.id', $own->getKey())
            ->where('dealTypes.3.isSystem', false));
});

it('adds a deal type to this team', function (): void {
    $this->post('/settings/deal-types', [
        'name' => 'Land Sale',
        'side' => DealSide::Sell->value,
    ])->assertRedirect('/settings/deal-types');

    $type = DealType::query()->where('name', 'Land Sale')->sole();

    expect($type->team_id)->toBe($this->team->getKey())
        ->and($type->side)->toBe(DealSide::Sell)
        // After everything already there, defaults included: a new type
        // belongs at the end of the picker rather than interleaved.
        ->and($type->sort_order)->toBe(3);
});

it('starts sort order at zero when there is nothing seeded', function (): void {
    // `(int) null + 1` gave the first type in an empty table a `sort_order` of
    // 1, out of step with the seeded rows which start at 0.
    DealType::query()->whereNull('team_id')->forceDelete();

    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Sell->value]);

    expect(DealType::query()->sole()->sort_order)->toBe(0);
});

it('never lets a request body choose the team', function (): void {
    [$other] = $this->teamWithMember();

    $this->post('/settings/deal-types', [
        'name' => 'Land Sale',
        'side' => DealSide::Sell->value,
        'team_id' => $other->getKey(),
    ])->assertRedirect();

    // `team_id` is absent from #[Fillable], so the posted one is dropped
    // before the controller sets the resolved one. Same rule as every other
    // table; this is the one model with no BelongsToTeam to enforce it.
    expect(DealType::query()->where('name', 'Land Sale')->sole()->team_id)
        ->toBe($this->team->getKey());
});

it('renames a type the team owns', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Land Sale']);

    $this->patch("/settings/deal-types/{$type->getKey()}", [
        'name' => 'Land & Lot Sale',
        'side' => DealSide::Sell->value,
    ])->assertRedirect('/settings/deal-types');

    expect($type->fresh()->name)->toBe('Land & Lot Sale');
});

it('lets a type keep its own name while its side is changed', function (): void {
    // The unique rule has to ignore the row it is validating, or nothing about
    // a type could ever be edited except its name.
    $type = DealType::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Land Sale']);

    $this->patch("/settings/deal-types/{$type->getKey()}", [
        'name' => 'Land Sale',
        'side' => DealSide::Buy->value,
    ])->assertSessionHasNoErrors();

    expect($type->fresh()->side)->toBe(DealSide::Buy);
});

it('refuses a duplicate name within the team', function (): void {
    DealType::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Land Sale']);

    $this->post('/settings/deal-types', [
        'name' => 'land sale',
        'side' => DealSide::Sell->value,
    ])->assertSessionHasErrors('name');

    expect(DealType::query()->where('team_id', $this->team->getKey())->count())->toBe(1);
});

it('refuses a name that shadows a system default', function (): void {
    /*
     * Nothing in the schema forbids this — the two partial indexes are
     * separate, one for system rows and one per team. But the picker would
     * show the same words twice with no way to tell them apart, and which
     * type a deal came back with would depend on which line was clicked.
     */
    $this->post('/settings/deal-types', [
        'name' => 'Buyer Representation',
        'side' => DealSide::Buy->value,
    ])->assertSessionHasErrors('name');
});

/**
 * PHP folds case one way and Postgres folds it another, and the rule has to
 * agree with the index rather than with PHP.
 *
 * `mb_strtolower('ΑΣ')` is `ας` — final-sigma folding — and Postgres `lower()`
 * gives `ασ`. `İ` folds to `i̇` in PHP and `i` in Postgres. So a duplicate
 * walked past a rule that folded its bind in PHP and hit
 * `deal_types_team_name_unique` as a 500, and two names Postgres considered
 * distinct were refused as the same. ASCII agrees, which is why every original
 * test here passed.
 */
it('folds case the way the index folds it', function (string $first, string $second): void {
    $this->post('/settings/deal-types', ['name' => $first, 'side' => DealSide::Sell->value])
        ->assertSessionHasNoErrors();

    $this->post('/settings/deal-types', ['name' => $second, 'side' => DealSide::Sell->value])
        ->assertSessionHasErrors('name');

    expect(DealType::query()->where('team_id', $this->team->getKey())->count())->toBe(1);
})->with([
    /*
     * **Order matters, and the first version of this got it backwards.**
     *
     * The old rule compared `lower(stored)` against a PHP-folded literal, so
     * whether it caught a pair depended on which name was stored first. Each
     * row below was checked against this database — `lower(stored) = <the PHP
     * folding of the second name>` — rather than assumed:
     *
     *   stored 'İstanbul Sale', typed 'Istanbul Sale'  → matched (caught)
     *   stored 'Istanbul Sale', typed 'İstanbul Sale'  → NO match (escaped)
     *   stored 'ΑΣ Sale',       typed 'ΑΣ Sale'        → NO match (escaped)
     *   stored 'Land Sale',     typed 'LAND SALE'      → matched (caught)
     *
     * So the two non-ASCII rows are the regression cases: each is one row to
     * the index and was two to the old rule, which is a duplicate walking past
     * validation into a 500. The ASCII row is a **control** — it always
     * worked, and it is here so a future narrowing of the rule cannot break
     * the ordinary case unnoticed.
     */
    'final sigma' => ['ΑΣ Sale', 'ΑΣ Sale'],
    'plain I stored, dotted I typed' => ['Istanbul Sale', 'İstanbul Sale'],
    'plain ascii (control — always worked)' => ['Land Sale', 'LAND SALE'],
]);

/**
 * Archiving frees the name — the migration says so in as many words, and it is
 * the documented way out of "I archived the wrong one, let me start clean".
 *
 * Both indexes are partial on `archived_at IS NULL` and the rule filtered only
 * `deleted_at`, so the row blocking the new name was rendered on the same
 * screen with an "Archived" badge and no explanation of why the name was
 * taken.
 */
it('frees the name when a type is archived', function (): void {
    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Sell->value]);

    $type = DealType::query()->where('name', 'Land Sale')->sole();

    $this->post("/settings/deal-types/{$type->getKey()}/archive")->assertSessionHasNoErrors();

    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Buy->value])
        ->assertSessionHasNoErrors();

    expect(DealType::query()->where('name', 'Land Sale')->count())->toBe(2);
});

/**
 * ...which makes restoring able to collide, so restoring asks the same
 * question creating does.
 *
 * Clearing `archived_at` moves the row back *into* the partial index. Without
 * the check this is a `UniqueConstraintViolationException` surfacing as an
 * error modal rather than as a sentence somebody can act on.
 */
it('refuses to restore a type whose name has been taken since', function (): void {
    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Sell->value]);

    $type = DealType::query()->where('name', 'Land Sale')->sole();

    $this->post("/settings/deal-types/{$type->getKey()}/archive");
    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Buy->value]);

    $this->post("/settings/deal-types/{$type->getKey()}/restore")
        ->assertSessionHasErrors('restore');

    // Still archived, and nothing 500d.
    expect($type->fresh()->isArchived())->toBeTrue();
});

it('refuses to rename an archived type', function (): void {
    // The screen already hid the button; the screen was the only thing hiding
    // it, on the one table with no global scope behind the policy. A rename
    // here is also a name freed and re-taken behind the validator's back.
    $type = DealType::factory()->create([
        'team_id' => $this->team->getKey(),
        'name' => 'Land Sale',
        'archived_at' => now(),
    ]);

    $this->patch("/settings/deal-types/{$type->getKey()}", [
        'name' => 'Renamed While Archived',
        'side' => DealSide::Sell->value,
    ])->assertForbidden();

    expect($type->fresh()->name)->toBe('Land Sale');
});

it('lets another team use a name this team has taken', function (): void {
    DealType::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Land Sale']);

    [$other, $otherOwner] = $this->teamWithOwner();
    $this->enrollTwoFactor($otherOwner);
    $this->actingAsPerson($otherOwner, $other);

    // The rule asks about this team and the shared rows, and nothing else —
    // or it would tell one team what another team has named its processes.
    $this->post('/settings/deal-types', [
        'name' => 'Land Sale',
        'side' => DealSide::Sell->value,
    ])->assertSessionHasNoErrors();

    expect(DealType::withoutTrashed()->where('name', 'Land Sale')->count())->toBe(2);
});

/**
 * The in-use warning, which is what S76 names and what makes this a lookup
 * screen rather than a CRUD form.
 */
it('counts the deals standing on a type', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    Deal::factory()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->get('/settings/deal-types')
        ->assertInertia(fn ($page) => $page
            ->where('dealTypes.3.dealCount', 3)
            // Still manageable — the warning informs the choice, it does not
            // remove it. Existing deals keep their type either way.
            ->where('dealTypes.3.canManage', true));
});

it('counts a deal whatever state it is in', function (): void {
    /*
     * A cancelled deal still renders with its type and still orphans if the
     * type goes, so an "in use" count that dropped it would understate the
     * thing the warning exists to warn about. The method used to be called
     * `liveDealCount` and count all states, which was a name promising a
     * filter that was not there.
     */
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    Deal::factory()->create(['team_id' => $this->team->getKey(), 'deal_type_id' => $type->getKey()]);

    $cancelled = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);
    $cancelled->transitionTo(DealState::Cancelled)->save();

    // A soft-deleted deal is already on its way out under the retention purge.
    Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ])->delete();

    expect($type->dealCount())->toBe(2);
});

it('archives a type instead of deleting it, and the deals keep it', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->post("/settings/deal-types/{$type->getKey()}/archive")
        ->assertRedirect('/settings/deal-types');

    expect($type->fresh()->isArchived())->toBeTrue()
        // The whole argument for archiving: the deal is untouched and still
        // renders with a type.
        ->and($deal->fresh()->deal_type_id)->toBe($type->getKey())
        ->and(DealType::query()->whereKey($type->getKey())->exists())->toBeTrue();
});

it('takes an archived type out of the picker but leaves it on the screen', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/settings/deal-types/{$type->getKey()}/archive");

    expect(DealType::query()->visibleTo($this->team)->selectable()->pluck('id')->all())
        ->not->toContain($type->getKey());

    // Still listed here, because this is the screen that undoes it.
    $this->get('/settings/deal-types')
        ->assertInertia(fn ($page) => $page->has('dealTypes', 4));
});

it('restores an archived type', function (): void {
    // Archiving is reversible and deleting is what is not — a screen that
    // archived with no way back would have talked somebody out of a delete and
    // handed them the same problem.
    $type = DealType::factory()->create([
        'team_id' => $this->team->getKey(),
        'archived_at' => now(),
    ]);

    $this->post("/settings/deal-types/{$type->getKey()}/restore")
        ->assertRedirect('/settings/deal-types');

    expect($type->fresh()->isArchived())->toBeFalse();
});

/**
 * The archive dialog promises *"no new deal will be able to use it"*.
 * Something has to keep that promise.
 *
 * `scopeSelectable()` takes an archived type out of the pickers, but a picker
 * is a suggestion: an id posted by hand, or held in a form somebody left open
 * while a colleague archived the type, reached the database unopposed.
 */
it('refuses to open a new deal on an archived type', function (): void {
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/settings/deal-types/{$type->getKey()}/archive")->assertSessionHasNoErrors();

    expect(fn () => Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->fresh()->getKey(),
    ]))->toThrow(ArchivedReferenceException::class);
});

it('leaves the deals already on a type alone when it is archived', function (): void {
    /*
     * The other half, and the more important one. Taking a type out of the
     * pickers must never strand the deals already on it — that is the whole
     * reason archiving exists here instead of deletion, and a guard that
     * refused every save would have turned the safe operation into the
     * destructive one.
     */
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    $deal = Deal::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_type_id' => $type->getKey(),
    ]);

    $this->post("/settings/deal-types/{$type->getKey()}/archive");

    $deal->fresh()->forceFill(['name' => '11 Ash Court'])->save();

    expect($deal->fresh()->name)->toBe('11 Ash Court')
        ->and($deal->fresh()->deal_type_id)->toBe($type->getKey());
});

it('has no route that deletes a deal type', function (): void {
    // Not "the destroy action refuses" — there is no destroy action. A route
    // that does not exist cannot be reached by guessing the verb.
    $type = DealType::factory()->create(['team_id' => $this->team->getKey()]);

    // 405 rather than 404: the path exists for other verbs, and DELETE is not
    // one of them. Either way there is nothing to reach.
    $this->delete("/settings/deal-types/{$type->getKey()}")->assertStatus(405);

    expect(DealType::query()->whereKey($type->getKey())->exists())->toBeTrue();
});

it('audits every change to a deal type', function (): void {
    // PRD §9. Archiving changes what a team can start, and "why can nobody
    // pick Rental Placement any more" is a question somebody asks in three
    // months.
    $this->post('/settings/deal-types', ['name' => 'Land Sale', 'side' => DealSide::Sell->value]);

    $type = DealType::query()->where('name', 'Land Sale')->sole();

    $this->patch("/settings/deal-types/{$type->getKey()}", [
        'name' => 'Land & Lot Sale',
        'side' => DealSide::Sell->value,
    ]);

    $this->post("/settings/deal-types/{$type->getKey()}/archive");
    $this->post("/settings/deal-types/{$type->getKey()}/restore");

    expect(AuditEntry::query()->whereIn('action', [
        'deal_type.created',
        'deal_type.updated',
        'deal_type.archived',
        'deal_type.restored',
    ])->pluck('action')->all())->toBe([
        'deal_type.created',
        'deal_type.updated',
        'deal_type.archived',
        'deal_type.restored',
    ]);
});
