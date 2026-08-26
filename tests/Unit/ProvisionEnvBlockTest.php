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
function requirePython(): void
{
    // The stage runs python3 on the droplet, where it is installed by the
    // script's own apt line. The application container is FrankenPHP on slim
    // Debian and has no python3, and no CI job runs this suite inside it — so
    // rather than failing with `python3: command not found` for somebody
    // running `make check`, say why it did not run.
    if (trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
        test()->markTestSkipped('python3 is not on PATH; the .env stage needs it.');
    }
}

function runEnvStage(string $directory, string $hostname = 'staging.example.com'): string
{
    requirePython();

    $script = base_path('scripts/provision-staging.sh');
    // Not tempnam().'.sh' — that creates one file and then writes to a
    // different path, orphaning the first, once per case in this file.
    $stage = $directory.'/stage.sh';

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

function countBlocks(string $env): int
{
    // Line-anchored, like the script's own search. A plain substring count
    // also counts a NOTE= line that quotes the delimiter, which is the very
    // fixture `it('still finds a real block…')` builds.
    return preg_match_all(
        '/^# >>> provision-staging\.sh — managed block/m', file_get_contents($env),
    );
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

it('wins over each competing spelling in the dataset below', function (string $line): void {
    // The spellings that defeated the in-place rewrite. Each is a valid Dotenv
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

it('resolves APP_KEY the way Dotenv would, for each spelling below', function (
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
    expect(countBlocks($this->workspace.'/.env'))->toBe(1);
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

it('refuses a file whose quoted value is never closed', function (): void {
    // Dotenv values may span lines, so an unclosed quote swallows everything
    // after it — including the appended block, which then resolves to nothing
    // while the run looks successful. The only safe answer is to stop.
    $env = $this->workspace.'/.env';
    file_put_contents($env, "MAIL_FROM_NAME=\"Acme Realty\nDB_PASSWORD=keep-me\n", LOCK_EX);

    $output = runEnvStage($this->workspace);

    expect($output)->toContain('never closed');
    // Refusing is only worth anything if it refuses without touching the file.
    expect(file_get_contents($env))->toContain('DB_PASSWORD=keep-me');
    expect(file_get_contents($env))->not->toContain('managed block');
});

it('refuses an opening delimiter with no closing one', function (): void {
    // A stray copy of the delimiter — pasted, or left by a hand-edit — used to
    // make the block match span from it to the real close, splicing out every
    // secret in between and reporting success.
    $env = $this->workspace.'/.env';
    file_put_contents($env, implode("\n", [
        '# >>> provision-staging.sh — managed block; re-run the script to change it',
        'DB_PASSWORD=postgres-has-this',
        'REDIS_PASSWORD=redis-has-this',
        '',
    ]), LOCK_EX);

    $output = runEnvStage($this->workspace);

    expect($output)->toContain('no closing one');
    expect(file_get_contents($env))->toContain('DB_PASSWORD=postgres-has-this');
    expect(file_get_contents($env))->toContain('REDIS_PASSWORD=redis-has-this');
});

it('refuses a stray opener sitting above a real block', function (): void {
    // The precise shape that a non-greedy match gets wrong: OPEN, secrets,
    // then a complete block. Matching from the first opener to the first close
    // would splice the secrets out. Distinct from the no-close-at-all case,
    // which a simple search already catches.
    $env = $this->workspace.'/.env';
    runEnvStage($this->workspace);
    file_put_contents($env, implode("\n", [
        '# >>> provision-staging.sh — managed block; re-run the script to change it',
        'DB_PASSWORD=postgres-has-this',
        '',
    ]).file_get_contents($env), LOCK_EX);

    $output = runEnvStage($this->workspace);

    expect($output)->toContain('no closing one');
    expect(file_get_contents($env))->toContain('DB_PASSWORD=postgres-has-this');
});

it('absorbs every stray complete block rather than stacking on them', function (): void {
    // Two strays, not one: with a single block, "remove them all" and "rewrite
    // the only one" are indistinguishable, and a mutation that removes only the
    // last used to pass. The secrets between the blocks have to survive too.
    $env = $this->workspace.'/.env';
    $stray = fn (string $host) => implode("\n", [
        '# >>> provision-staging.sh — managed block; re-run the script to change it',
        'SERVER_NAME='.$host,
        'APP_KEY=base64:FROM-'.$host.'=',
        '# <<< provision-staging.sh — end of managed block',
    ]);

    file_put_contents($env, implode("\n", [
        $stray('first.example.com'),
        'DB_PASSWORD=between-the-blocks',
        $stray('second.example.com'),
        'REDIS_PASSWORD=after-them',
        '',
    ]), LOCK_EX);

    runEnvStage($this->workspace, 'staging.example.com');

    $written = file_get_contents($env);
    $env_values = resolveEnv($this->workspace);

    // One block left, and it is ours.
    expect(countBlocks($env))->toBe(1);
    expect($env_values['SERVER_NAME'])->toBe('staging.example.com');
    // Content between and after the strays is not the script's to remove.
    expect($env_values['DB_PASSWORD'])->toBe('between-the-blocks');
    expect($env_values['REDIS_PASSWORD'])->toBe('after-them');
    // The LAST block's key carries forward, not the first — that is the one a
    // previous run of this script wrote.
    expect($env_values['APP_KEY'])->toBe('base64:FROM-second.example.com=');
});

it('reports an interpolated APP_KEY instead of judging it', function (): void {
    // `APP_KEY="${LEGACY_KEY}"` resolves to whatever LEGACY_KEY holds, which
    // the script cannot know — and this repo's own .env.example teaches the
    // shape with VITE_APP_NAME="${APP_PRODUCT_NAME}". Leaving theirs alone is
    // right; doing it silently is how a box ends up not booting.
    file_put_contents(
        $this->workspace.'/.env', "LEGACY_KEY=\nAPP_KEY=\"\${LEGACY_KEY}\"\n", LOCK_EX,
    );

    $output = runEnvStage($this->workspace);

    expect($output)->toContain('interpolated value');
    expect($output)->toContain('Check that APP_KEY is not empty');
});

it('says when a re-run changed nothing', function (): void {
    // The message an operator reads to decide whether anything happened. It
    // was unreachable for every input until round 2: `previous` never carries
    // a trailing newline and `rendered` always does, so they never compared
    // equal and every run claimed to have refreshed the block.
    runEnvStage($this->workspace);

    expect(runEnvStage($this->workspace))->toContain('already correct');
});

it('does not splice a line that merely quotes both delimiters', function (): void {
    // The delimiter search is anchored to a line start. Unanchored, this line
    // was spliced through — its contents deleted — while the script reported
    // that nothing outside the block was touched.
    $env = $this->workspace.'/.env';
    file_put_contents($env, implode("\n", [
        'NOTE="the delimiters are '
        .'# >>> provision-staging.sh — managed block; re-run the script to change it'
        .' and # <<< provision-staging.sh — end of managed block respectively"',
        'DB_PASSWORD=secret',
        '',
    ]), LOCK_EX);

    runEnvStage($this->workspace);

    expect(file_get_contents($env))->toContain('the delimiters are # >>>');
    expect(resolveEnv($this->workspace)['DB_PASSWORD'])->toBe('secret');
});

it('still finds a real block below a line that quotes the delimiters', function (): void {
    // The other half of the anchoring test. Asserting only that the NOTE= line
    // survives passes even if find_line gives up at the first mid-line hit and
    // finds no block at all — in which case every run stacks another one.
    $env = $this->workspace.'/.env';
    runEnvStage($this->workspace);
    file_put_contents($env, 'NOTE="the delimiters are '
        .'# >>> provision-staging.sh — managed block; re-run the script to change it'
        ." and # <<< provision-staging.sh — end of managed block respectively\"\n"
        .file_get_contents($env), LOCK_EX);

    runEnvStage($this->workspace);
    runEnvStage($this->workspace);

    expect(countBlocks($env))->toBe(1);
    expect(file_get_contents($env))->toContain('the delimiters are # >>>');
});

it('never rotates an interpolated APP_KEY, whatever it prints', function (): void {
    // "Theirs is left alone, because rotating a key that may be in use is the
    // worse error" is the branch's entire justification, and asserting only on
    // stdout leaves exactly that half untested.
    file_put_contents(
        $this->workspace.'/.env',
        "LEGACY_KEY=base64:THEIRS=\nAPP_KEY=\"\${LEGACY_KEY}\"\n",
        LOCK_EX,
    );

    runEnvStage($this->workspace);

    expect(resolveEnv($this->workspace)['APP_KEY'])->toBe('base64:THEIRS=');
    expect(file_get_contents($this->workspace.'/.env'))
        ->not->toContain('APP_KEY=base64:'.'"');
});

it('refuses a closing delimiter with no opener', function (): void {
    // The mirror of the stray-opener case. Duplicating the CLOSE line inside a
    // block strands the tail of the old one as loose settings lines, which is
    // not something to half-repair.
    $env = $this->workspace.'/.env';
    runEnvStage($this->workspace);
    file_put_contents($env, str_replace(
        '# <<< provision-staging.sh — end of managed block',
        "# <<< provision-staging.sh — end of managed block\n"
        .'# <<< provision-staging.sh — end of managed block',
        file_get_contents($env),
    ), LOCK_EX);
    file_put_contents($env, "DB_PASSWORD=keep-me\n".file_get_contents($env), LOCK_EX);

    $output = runEnvStage($this->workspace);

    expect($output)->toContain('no opening one');
    expect(file_get_contents($env))->toContain('DB_PASSWORD=keep-me');
});

it('anchors the closing-delimiter check to a line start', function (): void {
    // Unanchored, this refuses a file the script itself would have written —
    // any value that merely quotes the closing delimiter. Both anchoring tests
    // pass under that mutation, because a refusal leaves the file untouched.
    $env = $this->workspace.'/.env';
    file_put_contents($env, 'NOTE="the closing delimiter is '
        .'# <<< provision-staging.sh — end of managed block"'."\n", LOCK_EX);

    $output = runEnvStage($this->workspace);

    expect($output)->not->toContain('no opening one');
    expect(resolveEnv($this->workspace)['APP_ENV'])->toBe('staging');
    expect(file_get_contents($env))->toContain('the closing delimiter is # <<<');
});

it('does not claim lines moved when none did', function (): void {
    // The relocation notice asserted only positively is a notice that can fire
    // on every run without any test noticing — and a warning that always fires
    // is one an operator stops reading.
    $env = $this->workspace.'/.env';
    file_put_contents($env, "DB_PASSWORD=above-the-block\n", LOCK_EX);

    // First run: the block goes at the end, nothing was below it.
    expect(runEnvStage($this->workspace))->not->toContain('now above it');

    // Re-run with a different hostname: the block is rewritten in place, and
    // still nothing of theirs sits below it.
    expect(runEnvStage($this->workspace, 'other.example.com'))
        ->not->toContain('now above it');

    // Only when something is genuinely below the block.
    file_put_contents($env, "REDIS_PASSWORD=below-the-block\n", FILE_APPEND);

    expect(runEnvStage($this->workspace))->toContain('now above it');
});

it('measures relocation from the last block, not the first', function (): void {
    // Two blocks with a secret between them and nothing after the last. The
    // secret is already above where the new block lands, so nothing moves —
    // but measuring from the first block would see it as below and warn.
    $block = fn (string $host) => implode("\n", [
        '# >>> provision-staging.sh — managed block; re-run the script to change it',
        'SERVER_NAME='.$host,
        '# <<< provision-staging.sh — end of managed block',
    ]);

    file_put_contents($this->workspace.'/.env', implode("\n", [
        $block('first.example.com'),
        'DB_PASSWORD=between-the-blocks',
        $block('second.example.com'),
        '',
    ]), LOCK_EX);

    expect(runEnvStage($this->workspace))->not->toContain('now above it');
    expect(resolveEnv($this->workspace)['DB_PASSWORD'])->toBe('between-the-blocks');
});

it('reports the whole file, not just its own block', function (): void {
    // The message an operator reads to decide whether a re-run did anything.
    // "Already correct" for a file that changed is worse than the old
    // always-refreshed, because it errs in the direction they under-react to.
    $env = $this->workspace.'/.env';
    runEnvStage($this->workspace);

    expect(runEnvStage($this->workspace))->toContain('already correct');

    file_put_contents($env, "DB_PASSWORD=added-after-the-block\n", FILE_APPEND);
    $output = runEnvStage($this->workspace);

    expect($output)->not->toContain('already correct');
    // And their line is now above the block, so the block wins over it — which
    // "nothing was rewritten" does not convey on its own.
    expect($output)->toContain('now above it');
});

it('keeps CRLF a CRLF file and LF an LF file', function (): void {
    // Rewriting every line ending in somebody's file is exactly the kind of
    // edit this stage promises not to make.
    $env = $this->workspace.'/.env';
    file_put_contents($env, "DB_PASSWORD=x\r\nMAIL_FROM_NAME=y\r\n", LOCK_EX);

    runEnvStage($this->workspace);
    $written = file_get_contents($env);

    expect(substr_count($written, "\n"))->toBe(substr_count($written, "\r\n"));

    file_put_contents($env, "DB_PASSWORD=x\nFOO=y\n", LOCK_EX);
    runEnvStage($this->workspace);

    expect(file_get_contents($env))->not->toContain("\r");
});

it('carries a non-UTF-8 byte in a secret through untouched', function (): void {
    // Somebody's password, not a bug. Aborting with a decode traceback would
    // be the wrong answer, and so would replacing the byte.
    $env = $this->workspace.'/.env';
    file_put_contents($env, "DB_PASSWORD=p\xffw\nFOO=bar\n", LOCK_EX);

    runEnvStage($this->workspace);

    expect(file_get_contents($env))->toContain("DB_PASSWORD=p\xffw");
    expect(resolveEnv($this->workspace)['APP_ENV'])->toBe('staging');
});

it('follows a symlinked .env rather than replacing the link', function (): void {
    $target = $this->workspace.'/real.env';
    file_put_contents($target, "DB_PASSWORD=via-symlink\n", LOCK_EX);
    symlink($target, $this->workspace.'/.env');

    runEnvStage($this->workspace);

    // Replacing the link would orphan whatever it pointed at, which on a real
    // droplet is where the operator put the file deliberately.
    expect(is_link($this->workspace.'/.env'))->toBeTrue();
    expect(file_get_contents($target))->toContain('APP_ENV=staging');
});

it('leaves the file readable only by its owner', function (): void {
    runEnvStage($this->workspace);

    expect(substr(sprintf('%o', fileperms($this->workspace.'/.env')), -4))->toBe('0600');
});

it('gives an existing .env the product name it was provisioned before', function (): void {
    /*
     * Round 3 of review on #12. The stage copies `.env.example` only when the
     * file is **absent**, so a box provisioned before `APP_PRODUCT_NAME`
     * existed would never gain it, and `MAIL_FROM_NAME="${APP_NAME}"` would go
     * on resolving to the pre-rename codename on every message the product
     * itself writes.
     *
     * The managed block is the only part of the file this script owns, and
     * Dotenv reads the last definition, so putting the keys there is what
     * reaches an environment that already exists.
     *
     * **`VITE_APP_NAME` is not one of them**, and round 4 of review is why:
     * Vite compiles it into the bundle at build time, and the image builds
     * with `cp .env.example .env` while `.dockerignore` excludes `.env`. A
     * runtime value cannot reach the bundle, so writing one here would look
     * like a fix and change nothing.
     */
    file_put_contents(
        $this->workspace.'/.env',
        "APP_NAME=\"Brawling Mahogany\"\nAPP_KEY=base64:existing=\nVITE_APP_NAME=\"\${APP_NAME}\"\n",
        LOCK_EX,
    );

    runEnvStage($this->workspace);

    $env = file_get_contents($this->workspace.'/.env');

    expect($env)->toContain('APP_PRODUCT_NAME=Goldieflow')
        ->and($env)->toContain('MAIL_FROM_NAME=Goldieflow')
        // Not VITE_APP_NAME: a runtime value cannot reach a bundle Vite
        // compiled from `.env.example` inside the image.
        ->and($env)->not->toContain('VITE_APP_NAME=Goldieflow')
        // And the infrastructure identifier is left exactly where it was.
        ->and($env)->toContain('APP_NAME="Brawling Mahogany"');
});
