<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\SuppressionReason;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\MessageDelivery;
use App\Models\SuppressedAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The SNS endpoint, and what it refuses (#95 · PRD §8.5).
 *
 * Issue #95: *"an unauthenticated webhook that writes suppression records is
 * an obvious abuse target."* It is worse than that — suppression is this
 * product's one **account-wide** write, so anybody who could post here
 * unverified could stop it writing to any address they chose, for every team
 * on the platform, permanently.
 *
 * There are three checks and each of them is load-bearing. A test file that
 * only proved the happy path would be proving the one thing an attacker does
 * not care about.
 */
const TOPIC = 'arn:aws:sns:us-east-1:123456789012:goldieflow-ses';

beforeEach(function (): void {
    config(['services.ses.topic_arn' => TOPIC]);

    /*
     * A throwaway key per run, so the fixtures below are signed rather than
     * asserted about. A hard-coded signature would be a test of a string; this
     * is a test of `openssl_verify`.
     */
    $this->key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $this->key);
    $this->certificate = '';
    openssl_x509_export(openssl_csr_sign($csr, null, $this->key, 365), $this->certificate);

    Http::fake([
        'sns.us-east-1.amazonaws.com/*' => Http::response($this->certificate, 200),
    ]);
});

/**
 * Sign an envelope the way Amazon does: field-by-field, in their order, with
 * absent fields skipped rather than sent as empty.
 */
function signed(array $envelope): array
{
    $fields = $envelope['Type'] === 'SubscriptionConfirmation'
        ? ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type']
        : ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];

    $canonical = '';

    foreach ($fields as $field) {
        if (is_string($envelope[$field] ?? null)) {
            $canonical .= $field."\n".$envelope[$field]."\n";
        }
    }

    openssl_sign($canonical, $signature, test()->key, OPENSSL_ALGO_SHA256);

    return [
        ...$envelope,
        'SignatureVersion' => '2',
        'Signature' => base64_encode($signature),
        'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/cert.pem',
    ];
}

function bounceFor(string $messageId, string $email = 'dana@example.test'): array
{
    return signed([
        'Type' => 'Notification',
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => TOPIC,
        'Timestamp' => now()->toIso8601String(),
        'Message' => json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => $messageId],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => now()->toIso8601String(),
                'bouncedRecipients' => [
                    ['emailAddress' => $email, 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown'],
                ],
            ],
        ]),
    ]);
}

function post(array $envelope): Illuminate\Testing\TestResponse
{
    /*
     * `text/plain`, because that is what SNS genuinely sends and has for
     * years. A handler reading the body through the framework's content-type
     * sniffing gets an empty array from every real notification and returns an
     * empty 200 — which looks exactly like working.
     */
    return test()->call(
        'POST',
        '/webhooks/ses',
        server: ['CONTENT_TYPE' => 'text/plain'],
        content: json_encode($envelope),
    );
}

function deliveryRow(): MessageDelivery
{
    [$team] = test()->teamWithMember();

    $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

    $instance = ActionInstance::factory()->create([
        'team_id' => $team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    return MessageDelivery::factory()->create([
        'team_id' => $team->getKey(),
        'action_instance_id' => $instance->getKey(),
        'recipient_email' => 'dana@example.test',
        'provider_message_id' => 'ses-'.Str::lower((string) Str::ulid()),
    ]);
}

it('records a bounce that Amazon really signed', function (): void {
    $delivery = deliveryRow();

    post(bounceFor((string) $delivery->provider_message_id))->assertOk();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced)
        ->and($delivery->fresh()->detail)->toBe('smtp; 550 5.1.1 user unknown')
        ->and(SuppressedAddress::suppresses('dana@example.test'))
        ->toBe(SuppressionReason::HardBounce);
});

it('refuses a message from somebody else’s topic, however genuinely signed', function (): void {
    /*
     * The check people leave out. A valid Amazon signature only proves *some*
     * SNS topic sent this — anybody with an AWS account can create one, point
     * it at this URL, and have its notifications signed exactly as genuinely
     * as ours. Without the ARN check, that is a stranger with write access to
     * the platform's suppression list.
     */
    $delivery = deliveryRow();

    $envelope = bounceFor((string) $delivery->provider_message_id);
    $envelope['TopicArn'] = 'arn:aws:sns:us-east-1:999999999999:someone-else';

    post(signed(['Type' => 'Notification', ...$envelope]))->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and(SuppressedAddress::query()->count())->toBe(0);
});

it('refuses everything while no topic is configured', function (): void {
    /*
     * The other way round — treating an unset ARN as *"accept any topic"* —
     * is the shape of default that ships: it works in staging, nobody notices
     * it is unset in production, and the one check between a stranger's topic
     * and an account-wide suppression list is off.
     */
    config(['services.ses.topic_arn' => null]);

    $delivery = deliveryRow();

    post(bounceFor((string) $delivery->provider_message_id))->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('refuses a signature that does not verify', function (): void {
    $delivery = deliveryRow();

    $envelope = bounceFor((string) $delivery->provider_message_id);
    $envelope['Signature'] = base64_encode('not a signature at all');

    post($envelope)->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('refuses a body edited after it was signed', function (): void {
    /*
     * The point of signing the **canonical string** rather than the raw body.
     * `Message` is one of the signed fields, so swapping the payload for one
     * naming a different address cannot survive the check — which is exactly
     * the attack: take a genuine bounce for an address you control and rewrite
     * it to suppress somebody else's client.
     */
    $delivery = deliveryRow();

    $envelope = bounceFor((string) $delivery->provider_message_id);
    $tampered = json_decode($envelope['Message'], true);
    $tampered['bounce']['bouncedRecipients'] = [['emailAddress' => 'someone.else@example.test']];
    $envelope['Message'] = json_encode($tampered);

    post($envelope)->assertForbidden();

    expect(SuppressedAddress::query()->count())->toBe(0);
});

it('will not fetch a certificate from a host that merely mentions Amazon', function (): void {
    /*
     * `SigningCertURL` is attacker-controlled until it is checked: fetching
     * whatever it names lets somebody sign with their own key and hand over
     * the matching certificate. `str_contains($url, 'amazonaws.com')` passes
     * `https://evil.test/?x=amazonaws.com`, which is how this is usually got
     * wrong — so the pattern is anchored, and this is the case that proves it.
     */
    $delivery = deliveryRow();

    $envelope = bounceFor((string) $delivery->provider_message_id);
    $envelope['SigningCertURL'] = 'https://evil.test/cert.pem?x=sns.us-east-1.amazonaws.com';

    post($envelope)->assertForbidden();

    Http::assertNothingSent();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('acknowledges a signed message it does not model, rather than erroring', function (): void {
    /*
     * SNS retries a non-2xx for days and **disables the subscription** if it
     * keeps failing. A 500 on a payload this build does not understand would
     * first flood the endpoint and then silently turn bounce handling off
     * altogether — the failure this feature exists to prevent, reached by
     * defending against it clumsily.
     */
    post(signed([
        'Type' => 'Notification',
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => TOPIC,
        'Timestamp' => now()->toIso8601String(),
        'Message' => json_encode(['eventType' => 'Click', 'mail' => ['messageId' => 'whatever']]),
    ]))->assertOk();
});

it('acknowledges a bounce for a message it has never heard of', function (): void {
    // History, not an error: a bounce for something sent before this table
    // existed is a bounce there is nothing to record it against.
    post(bounceFor('a-message-from-before-all-this'))->assertOk();

    expect(SuppressedAddress::query()->count())->toBe(0);
});

it('confirms a subscription only through an Amazon URL', function (): void {
    $good = signed([
        'Type' => 'SubscriptionConfirmation',
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => TOPIC,
        'Timestamp' => now()->toIso8601String(),
        'Token' => 'a-token',
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
        'Message' => 'You have chosen to subscribe.',
    ]);

    post($good)->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ConfirmSubscription'));
});

it('does not follow a subscribe URL that is not Amazon’s', function (): void {
    $envelope = signed([
        'Type' => 'SubscriptionConfirmation',
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => TOPIC,
        'Timestamp' => now()->toIso8601String(),
        'Token' => 'a-token',
        'SubscribeURL' => 'https://evil.test/confirm',
        'Message' => 'You have chosen to subscribe.',
    ]);

    post($envelope)->assertOk();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.test'));
});

it('needs no session, and is reached without one', function (): void {
    /*
     * Registered outside the `web` group deliberately: a third party has no
     * session and no CSRF token. This asserts the routing rather than the
     * handler — a webhook that quietly acquired the `web` middleware would
     * start 419-ing every notification, and SNS would disable the
     * subscription while every other test here still passed.
     */
    $delivery = deliveryRow();

    auth()->logout();

    post(bounceFor((string) $delivery->provider_message_id))->assertOk();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced);
});
