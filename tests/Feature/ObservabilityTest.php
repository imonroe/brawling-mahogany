<?php

declare(strict_types=1);

use App\Logging\Redactor;
use App\Logging\ScrubPii;
use App\Logging\ScrubSentryEvents;
use App\Logging\StructuredLogging;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;

it('keeps the Horizon dashboard away from ordinary people', function (): void {
    // PRD §4.1: Horizon shows queue payloads, so it is a super-admin surface.
    $this->actingAs(User::factory()->create());

    $this->get('/horizon')->assertForbidden();
});

it('opens the Horizon dashboard to an authorised address', function (): void {
    config()->set('horizon.authorized_emails', 'ops@example.com');

    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']));

    $this->get('/horizon')->assertOk();
});

it('redirects every outbound message when the guardrail is set', function (): void {
    // The staging guardrail from PRD §8.6, exercised rather than trusted:
    // this test uses the real mailer over the array transport, because a
    // faked mailer would prove nothing about `alwaysTo`.
    Mail::clearResolvedInstances();
    app()->forgetInstance('mailer');
    app()->forgetInstance('mail.manager');

    config()->set('mail.redirect_to', 'staging@example.com');

    (new App\Providers\AppServiceProvider(app()))->boot();

    Mail::raw('Your inspection is scheduled', function ($message): void {
        $message->to('a-real-client@example.com')->subject('Update');
    });

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1);

    $recipients = array_map(
        fn ($address): string => $address->getAddress(),
        $sent[0]->getOriginalMessage()->getTo(),
    );

    expect($recipients)->toBe(['staging@example.com']);
});

it('refuses to boot in production with mail redirected', function (): void {
    config()->set('mail.redirect_to', 'staging@example.com');
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new App\Providers\AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'MAIL_REDIRECT_TO must be empty in production');
});

it('scrubs PII from every channel that can write', function (): void {
    /*
     * Written to the rule rather than to the code: it iterates whatever
     * channels are configured, so adding one without a tap fails here.
     *
     *  - `stack` delegates to the channels it names, which are checked.
     *  - `null` discards everything by construction.
     *  - `emergency` is built by the framework without taps and only fires
     *    when the configured channel itself throws (see config/logging.php).
     *  - `sentry` and `sentry_logs` are registered by the Sentry package and
     *    write nothing locally. `sentry` goes out through `before_send`;
     *    `sentry_logs` is a custom driver a tap could not reach anyway, and
     *    goes out through `before_send_log`. Both are asserted below.
     */
    $exempt = ['stack', 'null', 'emergency', 'sentry', 'sentry_logs'];

    // Naming the tap, not just counting them: `filled()` would be satisfied
    // by any tap at all, and the failure message claims something specific.
    $scrubbing = [ScrubPii::class, StructuredLogging::class];

    $unscrubbed = collect(config('logging.channels'))
        ->reject(fn (array $config, string $name): bool => in_array($name, $exempt, true))
        ->reject(fn (array $config): bool => array_intersect($scrubbing, $config['tap'] ?? []) !== [])
        ->keys()
        ->all();

    expect($unscrubbed)->toBe([], 'Every writing log channel needs a tap that installs RedactPii.');
});

it('sends nothing to Sentry that has not been redacted', function (): void {
    // Sentry's log breadcrumbs never pass through Monolog, so the config has
    // to carry its own redaction (config/sentry.php).
    expect(config('sentry.before_breadcrumb'))->toBe([ScrubSentryEvents::class, 'breadcrumb'])
        ->and(config('sentry.before_send'))->toBe([ScrubSentryEvents::class, 'event'])
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('none');

    $breadcrumb = ScrubSentryEvents::breadcrumb(new Breadcrumb(
        Breadcrumb::LEVEL_INFO,
        Breadcrumb::TYPE_DEFAULT,
        'log.info',
        'Sent the milestone email to emily@example.com',
        ['email' => 'emily@example.com', 'deal_id' => '01J8XZ'],
    ));

    expect($breadcrumb->getMessage())->not->toContain('@example.com')
        ->and($breadcrumb->getMetadata()['email'])->toBe(Redactor::REDACTED)
        ->and($breadcrumb->getMetadata()['deal_id'])->toBe('01J8XZ');
});

it('redacts the exception value, which is where PII usually hides', function (): void {
    /*
     * Laravel interpolates query bindings into the exception message, so the
     * most common exception in the framework carries whatever the query
     * filtered on. This is the path a breadcrumb callback never sees.
     */
    $exception = new QueryException(
        'pgsql',
        'select * from users where email = ?',
        ['emily@example.com'],
        new RuntimeException('SQLSTATE[42P01]: relation "users" does not exist'),
    );

    $event = ScrubSentryEvents::event(
        Event::createEvent()->setExceptions([new ExceptionDataBag($exception)]),
    );

    $value = $event->getExceptions()[0]->getValue();

    expect($value)->not->toContain('emily@example.com')
        ->and($value)->toContain(Redactor::REDACTED)
        // The useful half survives: it is still obvious which query broke.
        ->and($value)->toContain('select * from users');
});

it('redacts a Sentry Log record, which uses neither callback above', function (): void {
    // Sentry Logs bypass before_breadcrumb and before_send entirely.
    expect(config('sentry.before_send_log'))->toBe([ScrubSentryEvents::class, 'log']);

    $log = (new Log(1_755_000_000.0, str_repeat('0', 32), LogLevel::info(), 'Emailed emily@example.com'))
        ->setAttribute('client_email', 'emily@example.com')
        ->setAttribute('deal_id', '01J8XZ');

    $scrubbed = ScrubSentryEvents::log($log);

    expect($scrubbed->getBody())->not->toContain('@example.com')
        ->and($scrubbed->attributes()->toSimpleArray()['client_email'])->toBe(Redactor::REDACTED)
        ->and($scrubbed->attributes()->toSimpleArray()['deal_id'])->toBe('01J8XZ');
});
