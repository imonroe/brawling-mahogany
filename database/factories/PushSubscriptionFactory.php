<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            /*
             * Shaped like a real one — FCM's host and an opaque token — so a
             * test that accidentally depends on the endpoint being a URL
             * fails here rather than in production.
             */
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(152),
            /*
             * A **real** uncompressed P-256 point, not 65 random bytes.
             *
             * `minishlink/web-push` validates this on the way into RFC 8291's
             * key agreement and throws `"only uncompressed keys are
             * supported"` on anything that is not a point on the curve — so a
             * factory producing random bytes is a fixture that lies, and every
             * test of the send path dies inside the library instead of
             * exercising the branch it was written for.
             */
            'public_key' => self::publicKey(),
            'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            'last_seen_at' => now(),
        ];
    }

    /**
     * One uncompressed P-256 point, base64url, exactly as a browser hands it
     * over: the leading `0x04` marker followed by the 32-byte X and Y
     * coordinates, each left-padded — openssl returns them without leading
     * zeroes, and a 63-byte "point" is rejected for the same reason random
     * bytes are.
     */
    private static function publicKey(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('openssl could not generate a P-256 key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('openssl returned no EC coordinates.');
        }

        $point = "\x04"
            .str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return rtrim(strtr(base64_encode($point), '+/', '-_'), '=');
    }

    public function stale(): static
    {
        return $this->state(fn (): array => [
            'last_seen_at' => now()->subDays(PushSubscription::STALE_AFTER_DAYS + 1),
        ]);
    }
}
