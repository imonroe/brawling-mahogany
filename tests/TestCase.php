<?php

declare(strict_types=1);

namespace Tests;

use App\Actions\Teams\ProvisionTeam;
use App\Enums\SystemRole;
use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Database\Seeders\ReferenceDataSeeder;
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
    /**
     * The permission catalogue and the five system roles are reference data,
     * not fixtures. Half the product's behaviour is "does this role hold this
     * key", and a database without them answers no to everything for the
     * wrong reason.
     *
     * `RefreshDatabase` seeds once alongside the fresh migration rather than
     * per test, so this costs one run, not one per case.
     */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

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
     * Act as a person with credentials, inside a team.
     *
     * Almost every test needs a resolved team, because the global scope
     * (ADR 0002) throws without one — deliberately, so a forgotten context is
     * a loud failure rather than an empty list. Passing no team is still
     * valid: that is the "no access" case, and it has to be reachable.
     */
    protected function actingAsPerson(?Person $person = null, ?Team $team = null): Person
    {
        $person ??= Person::factory()->create();

        $this->actingAs($person);

        if ($team instanceof Team) {
            $this->withTeam($team);
        }

        return $person;
    }

    /**
     * Bind a team into the container and the session, the way the middleware
     * does, for tests that work against models rather than through routes.
     */
    protected function withTeam(Team $team): Team
    {
        app(TeamContext::class)->set($team);

        $this->withSession([ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);

        return $team;
    }

    /**
     * A team with a Team Owner who can sign in — the starting point for most
     * feature tests, and the shape PRD §5.1 step 1 provisions.
     *
     * @return array{0: Team, 1: Person}
     */
    protected function teamWithOwner(?Person $owner = null): array
    {
        $team = Team::factory()->create();
        $owner ??= Person::factory()->create();

        app(TeamContext::class)->runFor(
            $team,
            fn () => app(ProvisionTeam::class)->attachOwner($team, $owner),
        );

        return [$team, $owner];
    }

    /**
     * A team plus an ordinary Team Member.
     *
     * The default for a test that just needs somebody signed in and working.
     * `teamWithOwner()` is the *wrong* default for that: PRD §9 makes 2FA
     * mandatory for a Team Owner, so an un-enrolled owner is redirected to
     * the enrolment screen and every assertion about a page becomes a 302.
     *
     * @return array{0: Team, 1: Person}
     */
    protected function teamWithMember(?Person $person = null): array
    {
        [$team, $owner] = $this->teamWithOwner();

        $person ??= Person::factory()->create();

        app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
            $membership = TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => $person->getKey(),
                // What this team knows about them (#140). `first_name` is not
                // nullable, because a membership with no name renders as a
                // blank row on every screen that lists people.
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => $person->email,
                // A colleague holds no lifecycle value (#162), the way
                // `AcceptInvitation` leaves one.
                'joined_at' => now(),
            ]);

            $membership->roles()->attach(
                Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole()->getKey(),
            );
        });

        unset($owner);

        return [$team, $person];
    }

    /**
     * Give somebody the two-factor enrolment the mandate insists on.
     */
    protected function enrollTwoFactor(Person $person): Person
    {
        $person->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $person;
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
        // The container is rebuilt between tests, but the context is cheap to
        // clear and a leaked team is the one failure that would make the
        // isolation suite lie.
        app(TeamContext::class)->set(null);

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
