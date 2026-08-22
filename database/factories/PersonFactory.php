<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Most people in this product never log in (PRD F2.1), but a factory that
     * produced credential-less people by default would make every auth test
     * say `->withLogin()`. The default is a person who *can* sign in, and
     * `contactOnly()` is the explicit opposite.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'is_super_admin' => false,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * A person with no login: a client, a vendor, the other side's agent.
     *
     * PRD F2.1 makes this the common case, and issue #43 requires that a null
     * password never authenticates by any path.
     */
    public function contactOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => null,
            'email_verified_at' => null,
            'remember_token' => null,
        ]);
    }

    public function superAdministrator(): static
    {
        return $this->state(fn (array $attributes): array => ['is_super_admin' => true]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => ['email_verified_at' => null]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
