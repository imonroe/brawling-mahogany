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

    /**
     * Two records, and the split is what the screen is for (#140).
     *
     * The address is the account and lives on `people`; the name is what this
     * team calls them and lives on the membership. Somebody in two teams edits
     * their name once per team, which is correct.
     */
    public function test_profile_information_can_be_updated(): void
    {
        [$team, $user] = $this->teamWithMember();

        $this->actingAsPerson($user, $team);

        $this->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'test@example.com',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $membership = $user->membershipIn($team);

        $this->assertSame('Test', $membership->first_name);
        $this->assertSame('Person', $membership->last_name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_a_name_change_does_not_reach_another_team(): void
    {
        [$teamA, $person] = $this->teamWithMember();
        [$teamB] = $this->teamWithMember($person);

        $this->actingAsPerson($person, $teamA);

        $this->patch(route('profile.update'), [
            'first_name' => 'Renamed',
            'email' => $person->email,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $person->membershipIn($teamA)->first_name);
        $this->assertNotSame('Renamed', $person->membershipIn($teamB)->first_name);
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

    /**
     * Changing your sign-in address moves the address your team sees with it.
     *
     * `people.email` and `team_memberships.email` are two columns since #140,
     * and nothing kept them together: a member changed their login and the
     * members list, the directory search, and the export all went on showing
     * an address that no longer worked.
     */
    public function test_changing_the_login_address_updates_the_membership(): void
    {
        [$team, $user] = $this->teamWithMember();

        $this->actingAsPerson($user, $team);

        $membership = $user->membershipIn($team);
        $this->assertSame($user->email, $membership->email);

        $this->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'moved@example.test',
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('moved@example.test', $user->fresh()->email);
        $this->assertSame('moved@example.test', $membership->fresh()->email);
    }

    /**
     * ...but only the memberships that were carrying the old login address.
     *
     * A membership whose address a team typed for itself is that team's record
     * of how to reach somebody. Editing your own profile is not permission to
     * rewrite it.
     */
    public function test_it_leaves_an_address_a_team_typed_for_itself_alone(): void
    {
        [$team, $user] = $this->teamWithMember();

        $membership = $user->membershipIn($team);
        $membership->forceFill(['email' => 'work@example.test'])->save();

        $this->actingAsPerson($user, $team);

        $this->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'moved@example.test',
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('moved@example.test', $user->fresh()->email);
        $this->assertSame('work@example.test', $membership->fresh()->email);
    }

    /**
     * And never at the cost of a 500.
     *
     * One team cannot hold one address twice, and the address somebody is
     * moving to may already be in this team's directory as a colleague's
     * contact. The index would refuse the write; a stale address on the
     * members list is a better answer than a crash on the profile screen, and
     * the two columns exist precisely because they are allowed to disagree.
     */
    public function test_it_keeps_the_team_address_when_the_new_one_is_taken(): void
    {
        [$team, $user] = $this->teamWithMember();
        $original = $user->email;

        $this->actingAsPerson($user, $team);

        $this->post('/people', [
            'first_name' => 'Claire',
            'email' => 'claire@example.test',
            'status' => 'lead',
        ])->assertSessionHasNoErrors();

        $this->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'claire@example.test',
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('claire@example.test', $user->fresh()->email);
        $this->assertSame($original, $user->membershipIn($team)->email);
    }
}
