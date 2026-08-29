<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * The isolation suite's structural test.
 *
 * PRD §8.2 puts `team_id` on every business table and a global scope on every
 * business model. This makes the decision unskippable: a new model is either
 * tenant-scoped or explicitly recorded here as team-agnostic **with a reason**,
 * and adding one without deciding fails the build.
 *
 * Issue #42 asks for exactly this pairing — *"a test asserting that every
 * business table has `team_id` and every business model uses the trait"* — so
 * that a table added in Slice 4 is covered the day it lands rather than the
 * day somebody remembers.
 *
 * See docs/adr/0002-multi-tenancy-enforcement.md.
 */

/**
 * Models that legitimately carry no `team_id`.
 *
 * Every entry needs a reason. "It doesn't have one yet" is not a reason —
 * that is the case this test exists to catch.
 */
const TEAM_AGNOSTIC_MODELS = [
    // One record per human, shared across teams (issue #18, PRD §6.2). Team-
    // private data about a person lives on `team_memberships`.
    App\Models\Person::class,

    // The tenant boundary itself. Scoping it to a team would be circular.
    App\Models\Team::class,

    // The five access roles are shared by every team (`team_id` null); a
    // team's own composed roles carry one. A global scope would hide the
    // system five from everybody. Visibility is `Role::availableTo()`.
    App\Models\Role::class,

    // Flat, seeded in code, identical for every team (PRD §6.2). There is no
    // per-team permission catalogue.
    App\Models\Permission::class,

    // A credential belongs to a human, not to a tenancy: a person who works
    // for two teams signs in once. Scoped like `people`, which is to say not.
    App\Models\Passkey::class,

    /*
     * The same argument, one credential along (#103).
     *
     * A push subscription is an opaque endpoint URL plus the two keys that
     * authorise encrypting to it — a credential for a *browser*, held by a
     * human who has one phone however many agencies they work for. A
     * `team_id` would mean a row per team per device, and every send would
     * then have to de-duplicate by endpoint or push the same sentence to the
     * same lock screen twice.
     *
     * What makes this safe where `people` was not (ADR 0002, "the hole the
     * layers do not cover"): **every row describes a colleague's own
     * browser.** There is no client name, address or figure in this table and
     * nothing one team could learn about another by reading it. The day a
     * column here describes somebody a team works *with* rather than somebody
     * working *for* them, it belongs on a team-scoped table instead.
     *
     * Retention rides the cascade from `people`, which `records:purge`
     * already hard-deletes thirty days after an account goes — so this is not
     * a table outside the purge, which is the failure mode that argument
     * usually hides.
     */
    App\Models\PushSubscription::class,

    // The security record outlives the team it describes — issue #57 requires
    // the audit trail of a purge to survive the purge — and some entries have
    // no team at all. Reading it is gated by policy instead.
    App\Models\AuditEntry::class,

    /*
     * The definition layer, and the lookup beside it (Slice 2, issues #58 and
     * #64).
     *
     * These six share one shape and one reason. A null `team_id` means a
     * **system** row — a seeded deal type, or a template that arrived in a
     * pack — that every team can see; a set one means a row a team wrote for
     * itself. A global scope cannot express "mine or everybody's": applied to
     * these tables it would hide the seeded Listing pack from every team on
     * the platform, which is the opposite of the failure the scope exists to
     * prevent, and it would do it silently.
     *
     * So visibility is a named scope — `visibleTo($team)` on the two that a
     * team picks from — and the children inherit it through their parent
     * rather than carrying their own copy of the question.
     *
     * What makes this safe where `people` was not: **these hold no customer
     * data.** A stage template says "Listing Preparation, 5 days, owned by the
     * transaction coordinator". It says nothing about a client, an address, or
     * a price. The moment one of them holds a fact about a person, it belongs
     * in the runtime layer instead — which is team-scoped, by construction,
     * for exactly this reason. See ADR 0002, "The hole the layers do not
     * cover".
     */
    App\Models\DealType::class,
    App\Models\TemplatePack::class,
    App\Models\WorkflowTemplate::class,
    App\Models\StageTemplate::class,
    App\Models\GateTemplate::class,
    App\Models\TaskTemplate::class,

    /*
     * The seventh of that shape, and the one that had to *earn* it (Slice 3,
     * issue #91).
     *
     * An automation hangs off a `stage_template`, so its `team_id` mirrors its
     * parent's: null on a pack row every team shares, set on one a team wrote.
     * A global scope would hide the shared ones from everybody, which is the
     * same argument the six above make.
     *
     * What is different is that this row **points at a team-scoped table**.
     * `message_templates` is fully scoped — it holds a team's own words to
     * their own clients — so a shared automation naming one would send that
     * team's words from every other team on the platform. Being outside the
     * scope is therefore not free here, and two database constraints pay for
     * it: a composite foreign key over `(team_id, message_template_id)`, and a
     * CHECK that a row with no team may not name a template at all. The second
     * is the one that matters, because Postgres foreign keys are MATCH SIMPLE
     * and a null `team_id` satisfies one without checking anything.
     *
     * It carries no customer data itself: a trigger, an action type, and a
     * task title. The moment one holds a fact about a person it belongs in
     * `action_instances`, which is team-scoped by construction.
     */
    App\Models\ActionDefinition::class,

    /*
     * The only one that is team-agnostic because it is **deliberately
     * cross-tenant** rather than merely shared (Slice 3, #95).
     *
     * (No ordinal: an earlier draft said "the eighth", and a count written
     * into a comment beside a list is a claim nothing checks — which is the
     * reason CLAUDE.md points at this array rather than quoting a number.)
     *
     * A hard bounce is a fact about the address, not about the team that
     * happened to send to it: the mailbox does not exist for anybody. And SES
     * measures bounce and complaint rates across the **whole account** (PRD
     * §12.2 — bounce under 2%, complaint under 0.1%), so a per-team
     * suppression list would have each new team rediscover the same dead
     * address at every other team's expense.
     *
     * Issue #95 asks for it in as many words — *"this is the one place where a
     * deliberately cross-tenant record is correct, and it needs to be built
     * explicitly rather than falling out of a scope gap"* — and this entry is
     * where the decision is recorded rather than implied.
     *
     * What keeps it from being a disclosure: **nothing team-facing reads the
     * row.** `SuppressedAddress::suppresses()` returns a reason and nothing
     * else, `discovered_by_team_id` is console-only, and a team is told *"we
     * are not writing to this address"* in words about the address rather than
     * about another team's correspondence. It also outlives a team purge on
     * purpose (#57): the address is still dead afterwards, and having no
     * `team_id` is what makes that true by construction rather than by an
     * exception in the sweep.
     */
    App\Models\SuppressedAddress::class,
];

/**
 * Every model under app/Models, at any depth.
 *
 * Recursive on purpose: `app/Models/Deals/Deal.php` is a plausible layout, and
 * a directory-shaped hole in the test that guards tenancy is exactly the kind
 * of gap the ADR calls a release blocker.
 *
 * @return list<class-string<Model>>
 */
function appModels(): array
{
    $directory = app_path('Models');

    if (! is_dir($directory)) {
        return [];
    }

    $models = [];

    foreach ((new Finder)->files()->in([$directory])->name('*.php') as $file) {
        $class = 'App\\Models\\'.Str::replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

/**
 * The models the global scope protects.
 *
 * @return list<class-string<Model>>
 */
function tenantScopedModels(): array
{
    return array_values(array_filter(
        appModels(),
        fn (string $class): bool => in_array(BelongsToTeam::class, class_uses_recursive($class), true),
    ));
}

it('records a tenancy decision for every model', function (): void {
    $undecided = collect(appModels())
        ->reject(fn (string $class): bool => in_array($class, TEAM_AGNOSTIC_MODELS, true))
        ->reject(fn (string $class): bool => in_array(BelongsToTeam::class, class_uses_recursive($class), true));

    expect($undecided->all())->toBe(
        [],
        'Every model either uses BelongsToTeam or is listed in TEAM_AGNOSTIC_MODELS with a reason.',
    );
});

it('gives every tenant-scoped model a team_id column', function (): void {
    // The trait is one half. A trait on a table with no column is a global
    // scope constraining a column that does not exist, which is a 500 rather
    // than a leak — but it is still a gap, and it should fail here.
    $missing = collect(tenantScopedModels())
        ->reject(fn (string $class): bool => Schema::hasColumn((new $class)->getTable(), 'team_id'));

    expect($missing->all())->toBe([], 'A model using BelongsToTeam needs a team_id column.');
});

it('does not list a model as team-agnostic while it uses the trait', function (): void {
    // The two lists must not overlap: a stale entry in TEAM_AGNOSTIC_MODELS
    // reads as a deliberate exception long after it stopped being one.
    $contradictory = collect(TEAM_AGNOSTIC_MODELS)
        ->filter(fn (string $class): bool => in_array(BelongsToTeam::class, class_uses_recursive($class), true));

    expect($contradictory->all())->toBe([], 'Remove models from TEAM_AGNOSTIC_MODELS once they are scoped.');
});

it('constrains every tenant-scoped table to the teams table', function (): void {
    // Enforcement layer 2 (ADR 0002): the database's own half, so a row
    // cannot point at a tenant that does not exist.
    $unconstrained = collect(tenantScopedModels())
        ->reject(function (string $class): bool {
            $table = (new $class)->getTable();

            return collect(Schema::getForeignKeys($table))->contains(
                fn (array $key): bool => $key['columns'] === ['team_id'] && $key['foreign_table'] === 'teams',
            );
        });

    expect($unconstrained->all())->toBe([], 'A team-scoped table needs a foreign key on team_id.');
});
