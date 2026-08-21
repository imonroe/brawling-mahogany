<?php

declare(strict_types=1);

use App\Logging\Redactor;
use App\Logging\ScrubSentryEvents;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Sentry\Breadcrumb;

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
     *    write nothing locally; they go out through the SDK, which carries its
     *    own redaction (the assertions below).
     */
    $exempt = ['stack', 'null', 'emergency', 'sentry', 'sentry_logs'];

    $unscrubbed = collect(config('logging.channels'))
        ->reject(fn (array $config, string $name): bool => in_array($name, $exempt, true))
        ->reject(fn (array $config): bool => filled($config['tap'] ?? []))
        ->keys()
        ->all();

    expect($unscrubbed)->toBe([], 'Every writing log channel needs the ScrubPii tap.');
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
