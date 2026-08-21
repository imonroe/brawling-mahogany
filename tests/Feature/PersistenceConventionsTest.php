<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/adr/0001 says what every business table looks like. This proves the
 * macros actually produce it, against the database production runs.
 */
afterEach(function (): void {
    Schema::dropIfExists('convention_probe');
    Schema::dropIfExists('teams');
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
    // `people` may end up shared across teams (IA §13, open question 3), so
    // the convention has to have an explicit opt-out rather than a workaround.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults(teamScoped: false);
    });

    expect(Schema::hasColumn('convention_probe', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('convention_probe', 'deleted_at'))->toBeTrue();
});

it('can constrain team_id to the teams table', function (): void {
    // ADR 0002's second enforcement layer: the database's own half. `teams`
    // arrives in Slice 1, so it is created here — the point is to exercise
    // the macro's flag, not Laravel's `constrained()`.
    Schema::create('teams', function (Blueprint $table): void {
        $table->ulid('id')->primary();
    });

    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults(constrained: true);
    });

    $foreignKeys = collect(Schema::getForeignKeys('convention_probe'));

    expect($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->first()['columns'])->toBe(['team_id'])
        ->and($foreignKeys->first()['foreign_table'])->toBe('teams')
        ->and($foreignKeys->first()['on_delete'])->toBe('cascade');
});

it('leaves team_id unconstrained but indexed by default', function (): void {
    // The default is off only because `teams` does not exist yet; the column
    // and its index are not optional either way.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults();
    });

    expect(Schema::getForeignKeys('convention_probe'))->toBe([])
        ->and(collect(Schema::getIndexes('convention_probe'))
            ->contains(fn (array $index): bool => $index['columns'] === ['team_id']))->toBeTrue();
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
    $user = User::factory()->create(['email' => 'emily@example.com']);

    $user->delete();

    expect(User::withTrashed()->where('email', 'emily@example.com')->count())->toBe(1);

    $replacement = User::factory()->create(['email' => 'emily@example.com']);

    expect($replacement->exists)->toBeTrue()
        ->and(User::query()->where('email', 'emily@example.com')->count())->toBe(1);
});

it('still refuses two live accounts with the same address', function (): void {
    User::factory()->create(['email' => 'emily@example.com']);

    // Inside a savepoint: Postgres poisons a transaction after a failed
    // statement, and the surrounding test transaction has to survive it.
    expect(fn () => DB::transaction(
        fn () => User::factory()->create(['email' => 'emily@example.com']),
    ))->toThrow(QueryException::class);
});

it('lets somebody register again after deleting their account', function (): void {
    // The partial index is only half of it: the validation rule counts rows
    // itself, and this is the path a person actually travels.
    $user = User::factory()->create(['email' => 'emily@example.com']);
    $user->delete();

    $this->post('/register', [
        'name' => 'Emily Bosart',
        'email' => 'emily@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'emily@example.com')->count())->toBe(1);
});

it('still refuses to register a live address twice', function (): void {
    User::factory()->create(['email' => 'emily@example.com']);

    $this->post('/register', [
        'name' => 'Someone Else',
        'email' => 'emily@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasErrors('email');
});
