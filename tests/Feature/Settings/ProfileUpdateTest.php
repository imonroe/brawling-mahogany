<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = Person::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = Person::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'Person',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test', $user->first_name);
        $this->assertSame('Person', $user->last_name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_retyping_your_own_address_with_a_capital_is_not_a_500(): void
    {
        $person = Person::factory()->create(['email' => 'emily@example.test']);

        /*
         * `Rule::unique` compares verbatim while the index is over
         * `lower(email)`, so this passed validation and then hit the index —
         * a 500 whose Postgres DETAIL line carries the address straight into
         * the log (PRD §9: no PII in logs, ever).
         */
        $this->actingAs($person)
            ->patch(route('profile.update'), [
                'first_name' => 'Emily',
                'last_name' => 'Bosart',
                'email' => 'Emily@Example.TEST',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('emily@example.test', $person->fresh()->email);
    }

    public function test_somebody_elses_address_is_still_refused_whatever_its_capitals(): void
    {
        Person::factory()->create(['email' => 'heather@example.test']);
        $person = Person::factory()->create(['email' => 'emily@example.test']);

        $this->actingAs($person)
            ->patch(route('profile.update'), [
                'first_name' => 'Emily',
                'email' => 'Heather@Example.TEST',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = Person::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'Person',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = Person::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();

        // PRD §9: deletion is a soft delete with a 30-day recovery window,
        // then a hard delete. The record survives that window on purpose —
        // an account deleted by mistake on a Friday is recoverable on Monday.
        $this->assertSoftDeleted($user);
        $this->assertNull(Person::query()->find($user->id));
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = Person::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
