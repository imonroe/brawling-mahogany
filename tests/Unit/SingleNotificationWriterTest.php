<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Nothing but `Notify` raises a notification (issue #101 · PRD §4.12 F12.4).
 *
 * ## The claim this holds, stated exactly
 *
 * `App\Support\Notifications\Notify` is the only thing that **creates a row**
 * in `notifications`. It is deliberately *not* the only thing that ever writes
 * to the table: `NotificationController::read()` stamps `read_at`,
 * `SendNotification::deliver()` claims `delivered_at`, and both are updates to
 * a row somebody else already decided the shape of. Those two columns are the
 * *aftermath* of a notification; `channels` and `deliver_after` are the
 * notification itself.
 *
 * So the guard is about the **fan-out decision**, which is what issue #101
 * asks to be kept in one place:
 *
 * > The channel fan-out belongs in one place, not scattered across the
 * > features that raise notifications.
 *
 * Five features raise these — a task assignment, a gate clearing, an override,
 * a failing automation, an approaching deadline. A sixth written next slice
 * that inserts its own row is the failure this exists to catch, and it is a
 * quiet one: the row appears in the panel, so it *looks* like it worked, while
 * the preference somebody set on S78 was never consulted, quiet hours never
 * applied, and the summary never went through the truncation that keeps a long
 * task title from 500-ing the save.
 *
 * ## A source-reading test, for the reason the others are
 *
 * Same argument as `SingleMutationPathTest` and `ModelTenancyConventionTest`:
 * a runtime test only catches a path it thought to exercise, and the path that
 * breaks this is the one nobody thought about. Reading the source catches the
 * writer who never ran the test.
 *
 * ## And its candidate filter is half the guard
 *
 * `CLAUDE.md` records what `SingleMutationPathTest` cost to learn — it missed
 * `action_instances.state` for a whole slice because its **file filter** only
 * opened files mentioning `Stage`/`Workflow`/`Gate`. A class that bypasses
 * Eloquent has no reason to name the model, so `touchesNotifications()` admits
 * the table name and raw SQL as well, and `it('reads a file whose only signal
 * is the write itself')` pins that with fixtures naming no model at all.
 */

/** The calls that take an array of columns and write them. */
const NOTIFICATION_WRITE_CALLS = '(?:fill|forceFill|update|updateOrCreate|firstOrCreate|createQuietly|forceCreate|insert|insertGetId|create|setRawAttributes)';

/** One level of nesting inside the argument array. */
const NOTIFICATION_ARRAY_BODY = '(?:[^\[\]]|\[(?:[^\[\]]*)\])*';

const NOTIFICATION_WRITE_PATTERNS = [
    // new Notification — the shape `Notify` itself uses, and the shape a
    // feature copying it would use.
    '/\bnew\s+Notification\b/',
    // Notification::create([...]) and every sibling that inserts.
    '/\bNotification::\s*(?:create|forceCreate|createQuietly|insert|insertGetId|firstOrCreate|updateOrCreate|make)\s*\(/',
    // Notification::factory() outside a factory or a test — a fixture builder
    // reached for in application code is a row nobody decided the channels of.
    '/\bNotification::\s*factory\s*\(/',
    // $person->notifications()->create([...]) — a relation insert names no
    // model at all, which is exactly why it is listed.
    '/->\s*notifications\s*\(\s*\)\s*->\s*(?:create|forceCreate|createQuietly|createMany|insert|firstOrCreate|updateOrCreate|make)\s*\(/',

    /*
     * And the two columns that *are* the fan-out decision, in any write.
     *
     * A row created correctly and then re-channelled afterwards is the same
     * defect one step later: `deliver_after` is quiet hours, `channels` is the
     * preference, and something that rewrites either has decided for itself
     * what `Notify` exists to decide once.
     */
    '/->\s*channels\s*=(?!=)/',
    '/->\s*deliver_after\s*=(?!=)/',
    '/'.NOTIFICATION_WRITE_CALLS.'\s*\(\s*\['.NOTIFICATION_ARRAY_BODY.'[\'"](?:channels|deliver_after)[\'"]\s*=>/s',
    '/->\s*setAttribute\s*\(\s*[\'"](?:channels|deliver_after)[\'"]/',
    '/\[\s*[\'"](?:channels|deliver_after)[\'"]\s*\]\s*=(?!=)/',

    // Eloquent bypassed entirely — no model, no casts, no `Notify`.
    '/DB::\s*table\s*\(\s*[\'"]notifications[\'"]\s*\)/',
    '/\bINSERT\s+INTO\s+notifications\b/i',
    '/\bUPDATE\s+notifications\b/i',
];

/**
 * Files allowed to create a notification, each with the reason.
 *
 * Deliberately short. Two entries is the whole list, and a third should be
 * argued for rather than added.
 *
 * @var array<string, string>
 */
const SANCTIONED_NOTIFICATION_WRITERS = [
    'app/Support/Notifications/Notify.php' => 'The writer itself (#101). It is where a preference '.
        'is read, where quiet hours become a `deliver_after`, where a channel this build cannot '.
        'deliver is dropped, and where a summary is cut to fit the column.',

    'database/factories/NotificationFactory.php' => 'The same argument the workflow and action-'.
        'instance factories make in SingleMutationPathTest: a suite that could not build a held, '.
        'delivered or unread notification could not test the panel that reads one, or the sweep '.
        'that releases one.',
];

/**
 * Strip comments, keep strings.
 *
 * A docblock *describing* `['channels' => …]` is prose — this file is full of
 * them — and flagging it would be measuring the explanation rather than the
 * code. Strings stay, because `DB::table('notifications')` and a heredoc of
 * raw SQL are exactly what the last three patterns are for.
 */
function notificationCodeWithoutComments(string $contents): string
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** Does this source raise a notification, by any of the shapes above? */
function raisesNotifications(string $contents): bool
{
    $code = notificationCodeWithoutComments($contents);

    foreach (NOTIFICATION_WRITE_PATTERNS as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Does this source go anywhere near the table?
 *
 * The model, the table name, or raw SQL naming it. The table name is here for
 * the reason `SingleMutationPathTest` learned twice: the write that most needs
 * catching is the one that names no model, because naming no model is what
 * going around Eloquent *means*.
 *
 * `NotificationType`, `NotificationChannel`, `NotificationPreference` and
 * `NotificationFeed` all begin with the same word and none of them is this
 * model, so the model pattern requires `::` or a variable immediately after —
 * which is what a static call and a type hint look like, and what a
 * differently-suffixed class name never does.
 */
function touchesNotifications(string $contents): bool
{
    $code = notificationCodeWithoutComments($contents);

    foreach ([
        '/\bNotification(?:::|\s*\$)/',
        '/[\'"]notifications[\'"]/',
        '/->\s*notifications\s*\(/',
        '/\b(?:UPDATE|INSERT\s+INTO)\s+notifications\b/i',
    ] as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Classes under app/, routes/ and database/ that touch the table at all.
 *
 * `database/migrations` is excluded on purpose, as it is in
 * `SingleMutationPathTest`: a migration naming a column is what a migration
 * is, and `notifications.channels` has to be created by something.
 *
 * @return array<string, string> relative path => contents
 */
function filesTouchingNotifications(): array
{
    $found = [];

    $finder = (new Finder)
        ->files()
        ->in([app_path(), base_path('routes'), base_path('database')])
        ->notPath('migrations')
        ->name('*.php');

    foreach ($finder as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        if (touchesNotifications($contents)) {
            $path = str_replace('\\', '/', $file->getRealPath());
            $found[ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/')] = $contents;
        }
    }

    return $found;
}

it('lets nothing but Notify raise a notification', function (): void {
    $offenders = [];

    foreach (filesTouchingNotifications() as $path => $contents) {
        if (array_key_exists($path, SANCTIONED_NOTIFICATION_WRITERS)) {
            continue;
        }

        if (raisesNotifications($contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe(
        [],
        'A class outside App\Support\Notifications\Notify creates a notification or decides its '
        .'channels. Issue #101 puts the fan-out in one place so a preference set on S78 is honoured '
        .'by every feature that raises one, quiet hours apply to all of them, and a long summary is '
        .'cut before it reaches a varchar(255). Call Notify::send() instead. If this really is a '
        .'new legitimate writer, add it to SANCTIONED_NOTIFICATION_WRITERS with a reason.',
    );
});

/**
 * The guard, guarded.
 *
 * Every entry is a way of putting a row in that table, in a file that names
 * the model somewhere — the ordinary spelling. Pinning them means the next
 * person to widen the patterns cannot narrow them by accident.
 */
it('catches every shape of raising a notification', function (string $shape): void {
    $source = "<?php\n\nclass Sneaky\n{\n    public function run(Notification \$notification): void\n    {\n        {$shape}\n    }\n}\n";

    expect(touchesNotifications($source))->toBeTrue("The detector never even reads: {$shape}")
        ->and(raisesNotifications($source))->toBeTrue("The detector waves through: {$shape}");
})->with([
    'constructor' => ['$row = new Notification;'],
    'create' => ['Notification::create([\'summary\' => \'x\']);'],
    'force create' => ['Notification::forceCreate([\'summary\' => \'x\']);'],
    'quiet create' => ['Notification::createQuietly([\'summary\' => \'x\']);'],
    'factory in app code' => ['Notification::factory()->create();'],
    'channels property' => ['$notification->channels = [\'email\'];'],
    'channels array key' => ['$notification->forceFill([\'channels\' => [\'email\']])->save();'],
    'channels setAttribute' => ['$notification->setAttribute(\'channels\', [\'email\']);'],
    'channels payload key' => ['$payload = []; $payload[\'channels\'] = [\'email\']; $notification->forceFill($payload)->save();'],
    'quiet hours property' => ['$notification->deliver_after = null;'],
    'quiet hours array key' => ['$notification->update([\'deliver_after\' => null]);'],
    // A nested array before the key that matters, which a plain `[^\]]*`
    // would stop short of.
    'nested array' => ['$notification->update([\'data\' => [\'a\'], \'channels\' => [\'email\']]);'],
]);

/**
 * And the shapes that carry their own signal, in a fixture that names nothing.
 *
 * The filter admits a file when it sees the model, the table, or the relation.
 * These carry one of the last two *inside the write itself*, so they clear it
 * unaided — and the fixture has no type hint and no import, so nothing but the
 * shape can be doing it.
 *
 * That is the half `SingleMutationPathTest` got wrong twice: a fixture written
 * as `run(Stage $stage)` clears the filter on its **signature**, so it cannot
 * prove the filter reads anything. These take an untyped `$id`.
 */
it('reads a file whose only signal is the write itself', function (string $shape): void {
    $source = "<?php\n\nclass Sneaky\n{\n    public function run(\$id, \$person): void\n    {\n        {$shape}\n    }\n}\n";

    expect(touchesNotifications($source))->toBeTrue("The detector never even reads: {$shape}")
        ->and(raisesNotifications($source))->toBeTrue("The detector waves through: {$shape}");
})->with([
    'relation create' => ['$person->notifications()->create([\'summary\' => \'x\']);'],
    'relation create many' => ['$person->notifications()->createMany([[\'summary\' => \'x\']]);'],
    'query builder insert' => ['DB::table(\'notifications\')->insert([\'summary\' => \'x\']);'],
    'query builder rechannel' => ['DB::table(\'notifications\')->whereKey($id)->update([\'channels\' => \'[]\']);'],
    'raw insert' => ['DB::statement(\'INSERT INTO notifications (summary) VALUES (\\\'x\\\')\');'],
    'raw rechannel' => ['DB::statement(\'UPDATE notifications SET deliver_after = NULL\');'],
]);

/**
 * And what it must stay quiet about.
 *
 * The two updates that are the *aftermath* of a notification rather than the
 * decision — and they matter more than the usual "no false positives" case,
 * because the failure message's own remedy is to add the file to
 * `SANCTIONED_NOTIFICATION_WRITERS`. A guard that flagged `read_at` would be
 * answered by exempting the controller that stamps it, for the rest of that
 * controller's life, earned by a read receipt.
 *
 * The prop map is here for the same reason `SingleMutationPathTest` keeps one:
 * `NotificationFeed` serialises `channels` onto a page, and a pattern that
 * could not tell a serialisation from a write would flag the feed.
 */
it('stays quiet about the writes that are not the decision', function (string $shape): void {
    $source = "<?php\n\nclass Innocent\n{\n    public function run(Notification \$notification): void\n    {\n        {$shape}\n    }\n}\n";

    expect(raisesNotifications($source))->toBeFalse("A false positive on: {$shape}");
})->with([
    'marking read' => ['Notification::query()->whereKey($notification->id)->update([\'read_at\' => now()]);'],
    'claiming delivery' => ['Notification::query()->whereNull(\'delivered_at\')->update([\'delivered_at\' => now()]);'],
    'reading the panel' => ['$rows = Notification::query()->forPerson($notification->person)->unread()->get();'],
    'purging' => ['Notification::query()->where(\'read_at\', \'<\', now())->delete();'],
    'serialising to a page' => ['$props = [\'channels\' => $notification->channels, \'deliver_after\' => $notification->deliver_after];'],
]);
