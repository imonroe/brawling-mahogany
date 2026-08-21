<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

/**
 * The base every test extends. See docs/Testing.md for the conventions.
 *
 * Nothing escapes a test run: mail and notifications are faked, the default
 * filesystem disk is a temporary one, and any HTTP request to a host the test
 * did not explicitly fake fails loudly rather than reaching a real provider.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake(config()->string('filesystems.default'));

        // SES, the AI provider, and every webhook live behind the HTTP client.
        // A test that reaches one of them for real is a test that can cost
        // money or send a message to somebody's client.
        Http::preventStrayRequests();
    }

    /**
     * Act as a person with credentials.
     *
     * Team context arrives with tenancy in Slice 1 (epic #2), where this gains
     * a `$team` argument and a companion assertion for cross-tenant refusal.
     */
    protected function actingAsPerson(?User $user = null): User
    {
        $user ??= User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    /**
     * Freeze the clock at a fixed instant.
     *
     * This product is dates, deadlines, and derived offsets. A test that
     * depends on "now" without pinning it is a test that fails at midnight.
     */
    protected function freezeAt(string $instant): Carbon
    {
        $frozen = Carbon::parse($instant, 'UTC');

        Carbon::setTestNow($frozen);

        return $frozen;
    }

    /**
     * Opt in to a faked queue for tests that assert dispatch rather than
     * behaviour. By default the queue runs synchronously, so a feature test
     * exercises the job it dispatches instead of asserting that it would.
     */
    protected function fakeQueue(): void
    {
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
