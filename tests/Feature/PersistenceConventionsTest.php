<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/adr/0001 says what every business table looks like. This proves the
 * macros actually produce it, against the database production runs.
 */
afterEach(function (): void {
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
    // `people` may end up shared across teams (IA §13, open question 3), so
    // the convention has to have an explicit opt-out rather than a workaround.
    Schema::create('convention_probe', function (Blueprint $table): void {
        $table->productDefaults(teamScoped: false);
    });

    expect(Schema::hasColumn('convention_probe', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('convention_probe', 'deleted_at'))->toBeTrue();
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
