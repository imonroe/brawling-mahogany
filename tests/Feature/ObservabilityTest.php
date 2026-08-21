<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

it('scrubs PII from anything written to a log channel', function (): void {
    // The channels are configured with the tap; this asserts the wiring, not
    // the processor (tests/Unit/RedactPiiTest.php covers the rules).
    foreach (['single', 'daily', 'stderr', 'stdout'] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->not->toBeEmpty();
    }
});
