<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\DealType;
use App\Support\Tenancy\TeamContext;

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
            ->where('dealTypes.3.liveDealCount', 3)
            // Still archivable — the warning informs the choice, it does not
            // remove it. Existing deals keep their type either way.
            ->where('dealTypes.3.canArchive', true));
});

it('counts only this team’s deals on a shared type', function (): void {
    // The leak this closes: a system type is one row shared by every team, so
    // an unscoped count would tell one team how many deals every other team is
    // running.
    $system = DealType::query()->whereNull('team_id')->where('name', 'Buyer Representation')->sole();

    Deal::factory()->create(['team_id' => $this->team->getKey(), 'deal_type_id' => $system->getKey()]);

    [$other] = $this->teamWithMember();

    app(TeamContext::class)->runFor($other, fn () => Deal::factory()->count(4)->create([
        'team_id' => $other->getKey(),
        'deal_type_id' => $system->getKey(),
    ]));

    app(TeamContext::class)->runFor($this->team, function () use ($system): void {
        expect($system->liveDealCount())->toBe(1);
    });
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
