<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * docs/adr/0001 says what every business table looks like. This proves the
 * macros actually produce it, against the database production runs.
 */
afterEach(function (): void {
    Schema::dropIfExists('convention_probe_child');
    Schema::dropIfExists('convention_probe');
});

it('gives a business table a ULID key, a team, timestamps, and soft deletes', function (): void {
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
        $table->string('name');
    });

    expect(Schema::hasColumns('convention_probe', [
        'id', 'team_id', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();

    // ULIDs are 26 characters, stored as a fixed-width char column.
    expect(Schema::getColumnType('convention_probe', 'id'))->toBe('bpchar');
    expect(Schema::getColumnType('convention_probe', 'team_id'))->toBe('bpchar');
});

it('allows a table that is deliberately not team-scoped', function (): void {
    // `people` is shared across teams (issue #18), and `audit_log` outlives
    // the team it describes. The convention needs an explicit opt-out rather
    // than a workaround, and each use of it is recorded in the isolation
    // suite with a reason.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults(teamScoped: false);
    });

    expect(Schema::hasColumn('convention_probe', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('convention_probe', 'deleted_at'))->toBeTrue();
});

it('constrains team_id to the teams table by default', function (): void {
    // ADR 0002's second enforcement layer: the database's own half. ADR 0001
    // said this default would flip once `teams` existed, and Slice 1 is when
    // it did.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
    });

    $foreignKeys = collect(Schema::getForeignKeys('convention_probe'));

    expect($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->first()['columns'])->toBe(['team_id'])
        ->and($foreignKeys->first()['foreign_table'])->toBe('teams')
        ->and($foreignKeys->first()['on_delete'])->toBe('cascade');
});

it('gives every team-scoped table the composite key a child can point at', function (): void {
    // A composite foreign key over `(team_id, id)` is only accepted by
    // Postgres if the parent carries a unique index over both columns. This
    // is what makes `teamScopedForeign()` possible at all.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
    });

    expect(collect(Schema::getIndexes('convention_probe'))
        ->contains(fn (array $index): bool => $index['columns'] === ['team_id', 'id'] && $index['unique']))
        ->toBeTrue();
});

it('makes a cross-tenant pointer unrepresentable', function (): void {
    // ADR 0002, layer 2: "a `task` pointing at a `stage` in another team is
    // then not merely unlikely, it is unrepresentable." Proven against the
    // real database, because that is the only place the claim is true.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
    });

    Schema::create('convention_probe_child', function (Blueprint $table): void {
        $table->productDefaults();
        $table->teamScopedForeign('parent_id', 'convention_probe');
    });

    $teams = Team::factory()->count(2)->create();

    $parent = DB::table('convention_probe')->insertGetId([
        'id' => (string) Str::ulid(), 'team_id' => $teams[0]->getKey(),
    ], 'id');

    // Inside a savepoint: Postgres poisons a transaction after a failed
    // statement, and the surrounding test transaction has to survive it.
    expect(fn () => DB::transaction(fn () => DB::table('convention_probe_child')->insert([
        'id' => (string) Str::ulid(),
        // The other team's id, pointing at the first team's parent row.
        'team_id' => $teams[1]->getKey(),
        'parent_id' => $parent,
    ])))->toThrow(QueryException::class);

    // The same insert with the matching team is accepted, so the test is
    // proving the composite key rather than a broken table.
    DB::table('convention_probe_child')->insert([
        'id' => (string) Str::ulid(),
        'team_id' => $teams[0]->getKey(),
        'parent_id' => $parent,
    ]);

    expect(DB::table('convention_probe_child')->count())->toBe(1);
});

it('stores money as integer cents and config as JSONB', function (): void {
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
        $table->money('transaction_value', nullable: true);
        $table->config('settings');
    });

    // Not numeric, not double precision: a house price is not a float.
    expect(Schema::getColumnType('convention_probe', 'transaction_value'))->toBe('int8')
        ->and(Schema::getColumnType('convention_probe', 'settings'))->toBe('jsonb');
});

it('frees a deleted account’s email address', function (): void {
    // Soft delete plus a plain unique index would reserve the address
    // forever, so nobody could sign up again after deleting their account.
    // The index is partial: unique among the living only (see the migration).
    $user = Person::factory()->create(['email' => 'emily@example.com']);

    $user->delete();

    expect(Person::withTrashed()->where('email', 'emily@example.com')->count())->toBe(1);

    $replacement = Person::factory()->create(['email' => 'emily@example.com']);

    expect($replacement->exists)->toBeTrue()
        ->and(Person::query()->where('email', 'emily@example.com')->count())->toBe(1);
});

it('still refuses two live accounts with the same address', function (): void {
    Person::factory()->create(['email' => 'emily@example.com']);

    // Inside a savepoint: Postgres poisons a transaction after a failed
    // statement, and the surrounding test transaction has to survive it.
    expect(fn () => DB::transaction(
        fn () => Person::factory()->create(['email' => 'emily@example.com']),
    ))->toThrow(QueryException::class);
});

it('lets somebody register again after deleting their account', function (): void {
    // The partial index is only half of it: the validation rule counts rows
    // itself, and this is the path a person actually travels.
    $user = Person::factory()->create(['email' => 'emily@example.com']);
    $user->delete();

    $this->post('/register', [
        'first_name' => 'Emily',
        'last_name' => 'Bosart',
        'email' => 'emily@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors();

    expect(Person::query()->where('email', 'emily@example.com')->count())->toBe(1);
});

it('still refuses to register a live address twice', function (): void {
    Person::factory()->create(['email' => 'emily@example.com']);

    $this->post('/register', [
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => 'emily@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasErrors('email');
});
