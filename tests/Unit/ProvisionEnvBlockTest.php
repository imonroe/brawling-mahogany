<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/**
 * The .env stage of scripts/provision-staging.sh, held against real Dotenv.
 *
 * This exists because five review rounds were spent on that stage rewriting
 * keys in place with `^KEY=.*$` — a regex that is not Dotenv's grammar, while
 * Dotenv resolves a key to its LAST definition. Every round found one more
 * spelling that slipped past and silently won.
 *
 * The script now appends a managed block instead of editing, so the block wins
 * by position rather than by matching. These tests assert that through the same
 * parser Laravel uses, so "it wins" is measured rather than argued.
 */
function runEnvStage(string $directory, string $hostname = 'staging.example.com'): string
{
    $script = base_path('scripts/provision-staging.sh');
    $stage = tempnam(sys_get_temp_dir(), 'stage').'.sh';

    // Just the .env stage: the rest of the script installs Docker and a
    // firewall, which no test may run.
    $source = file_get_contents($script);
    $start = strpos($source, 'say ".env"');
    $end = strpos($source, "\ncat <<EOF\n");

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    file_put_contents($stage, implode("\n", [
        'set -euo pipefail',
        'DEPLOY_USER="$(id -un)"',
        'DEPLOY_PATH="$1"',
        'SERVER_NAME="$2"',
        'say() { :; }',
        // The real script runs the rewrite as the deploy user; a test has no
        // second account, so sudo drops its `-u <user>` and runs it directly.
        'sudo() { shift 2; "$@"; }',
        substr($source, $start, $end - $start),
    ]), LOCK_EX);

    $output = shell_exec(
        'bash '.escapeshellarg($stage).' '.escapeshellarg($directory)
        .' '.escapeshellarg($hostname).' 2>&1',
    );

    unlink($stage);

    return (string) $output;
}

function resolveEnv(string $directory): array
{
    return Dotenv::createArrayBacked($directory, '.env')->load();
}

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/provision-'.bin2hex(random_bytes(6));
    mkdir($this->workspace, 0o700, true);
    copy(base_path('.env.example'), $this->workspace.'/.env.example');
});

afterEach(function (): void {
    foreach (glob($this->workspace.'/{,.}[!.,]*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->workspace);
});

it('wins over every spelling of a key that Dotenv honours', function (string $line): void {
    // The four that defeated the in-place rewrite. Each is a valid Dotenv
    // definition and each was the last one in the file, so each used to win.
    copy($this->workspace.'/.env.example', $this->workspace.'/.env');
    file_put_contents($this->workspace.'/.env', "\n".$line."\n", FILE_APPEND);

    runEnvStage($this->workspace);

    expect(resolveEnv($this->workspace)['APP_ENV'])->toBe('staging');
})->with([
    'plain' => ['APP_ENV=production'],
    'export' => ['export APP_ENV=production'],
    'spaces around =' => ['APP_ENV = production'],
    'indented' => ['   APP_ENV=production'],
    'tabs' => ["APP_ENV\t=\tproduction"],
    'quoted' => ['APP_ENV="production"'],
]);

it('never touches anything outside its own block', function (): void {
    $env = $this->workspace.'/.env';
    copy($this->workspace.'/.env.example', $env);
    file_put_contents($env, str_replace(
        'DB_PASSWORD=', 'DB_PASSWORD=postgres-is-using-this', file_get_contents($env),
    ), LOCK_EX);

    runEnvStage($this->workspace);

    // The password Postgres already holds cannot be recovered if it is lost,
    // which is the whole reason this stage appends rather than edits.
    expect(resolveEnv($this->workspace)['DB_PASSWORD'])->toBe('postgres-is-using-this');
});

it('resolves APP_KEY the way Dotenv would, for every spelling of empty', function (
    string $line,
    bool $expectTheirs,
): void {
    file_put_contents($this->workspace.'/.env', $line."\n", LOCK_EX);

    runEnvStage($this->workspace);

    $key = resolveEnv($this->workspace)['APP_KEY'];

    // An empty APP_KEY means the application will not boot, so every empty
    // spelling has to produce a generated key — and every set one has to be
    // left exactly alone, since rotating it invalidates every session.
    expect($key)->not->toBe('');
    expect(str_contains($key, 'THEIRS'))->toBe($expectTheirs);
})->with([
    'bare empty' => ['APP_KEY=', false],
    'double-quoted empty' => ['APP_KEY=""', false],
    'single-quoted empty' => ["APP_KEY=''", false],
    'whitespace only' => ['APP_KEY=   ', false],
    'comment only' => ['APP_KEY= # rotate me', false],
    'comment, no space' => ['APP_KEY=#todo', false],
    'set' => ['APP_KEY=base64:THEIRS=', true],
    'set with export' => ['export APP_KEY=base64:THEIRS=', true],
    'set, spaced' => ['  APP_KEY = base64:THEIRS=', true],
    'set and quoted' => ['APP_KEY="base64:THEIRS="', true],
    'set with a trailing comment' => ['APP_KEY=base64:THEIRS= # keep', true],
]);

it('takes the last APP_KEY, because that is the one Dotenv reads', function (): void {
    // Set first, blanked second: Dotenv resolves to empty, so a key is needed
    // even though a non-empty line exists earlier in the file.
    file_put_contents(
        $this->workspace.'/.env', "APP_KEY=base64:THEIRS=\nAPP_KEY=\n", LOCK_EX,
    );

    runEnvStage($this->workspace);

    expect(resolveEnv($this->workspace)['APP_KEY'])->not->toBe('');
});

it('is idempotent: one block, and a key that never rotates', function (): void {
    runEnvStage($this->workspace);
    $first = resolveEnv($this->workspace)['APP_KEY'];

    runEnvStage($this->workspace);
    runEnvStage($this->workspace);

    expect(resolveEnv($this->workspace)['APP_KEY'])->toBe($first);
    expect(substr_count(
        file_get_contents($this->workspace.'/.env'), 'managed block; re-run',
    ))->toBe(1);
});

it('picks up a new hostname without disturbing the rest', function (): void {
    runEnvStage($this->workspace, 'first.example.com');
    $key = resolveEnv($this->workspace)['APP_KEY'];

    runEnvStage($this->workspace, 'second.example.com');
    $env = resolveEnv($this->workspace);

    expect($env['SERVER_NAME'])->toBe('second.example.com');
    expect($env['APP_URL'])->toBe('https://second.example.com');
    expect($env['APP_KEY'])->toBe($key);
});

it('sets the infrastructure values the droplet actually needs', function (): void {
    runEnvStage($this->workspace);
    $env = resolveEnv($this->workspace);

    expect($env['APP_ENV'])->toBe('staging');
    expect($env['APP_DEBUG'])->toBe('false');
    // ACME's challenges arrive on 80 and 443 and nowhere else.
    expect($env['APP_PORT'])->toBe('80');
    expect($env['APP_TLS_PORT'])->toBe('443');
    // Migrations are a deploy step, never a container start-up side effect.
    expect($env['AUTO_MIGRATE'])->toBe('false');
    // Or a bare `docker compose up` on the droplet rebuilds it as the dev image.
    expect($env['COMPOSE_FILE'])->toBe('compose.yaml');
});

it('leaves the file readable only by its owner', function (): void {
    runEnvStage($this->workspace);

    expect(substr(sprintf('%o', fileperms($this->workspace.'/.env')), -4))->toBe('0600');
});
