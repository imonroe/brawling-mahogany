<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Team;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

it('PROBE 1: 2FA mandate does not stop an unenrolled owner reaching team settings/export', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    expect(app(App\Support\TwoFactorMandate::class)->applies($owner))->toBeTrue();

    $this->actingAs($owner);
    $this->withSession([App\Http\Middleware\ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);

    $export = $this->get('/settings/export');
    $team_ = $this->get('/settings/team');
    $members = $this->get('/settings/members');
    $dash = $this->get('/dashboard');

    dump([
        'settings/export' => $export->getStatusCode().' -> '.($export->headers->get('Location') ?? '-'),
        'settings/team' => $team_->getStatusCode().' -> '.($team_->headers->get('Location') ?? '-'),
        'settings/members' => $members->getStatusCode().' -> '.($members->headers->get('Location') ?? '-'),
        'dashboard' => $dash->getStatusCode().' -> '.($dash->headers->get('Location') ?? '-'),
    ]);

    // and can actually START an export
    $post = $this->post('/settings/export', []);
    dump(['POST settings/export' => $post->getStatusCode().' -> '.($post->headers->get('Location') ?? '-')]);
    dump(['exports created' => DB::table('data_exports')->count()]);
})->group('probe');

it('PROBE 2: a person with no email cannot be created', function (): void {
    [$team, $person] = $this->teamWithMember();
    $this->withTeam($team);

    try {
        $m = app(App\Actions\People\SavePerson::class)->create([
            'first_name' => 'Phoneonly',
            'last_name' => null,
            'email' => null,
            'phone' => '555-1234',
            'status' => App\Enums\PersonLifecycleState::Lead->value,
        ]);
        dump(['created' => $m->getKey()]);
    } catch (Throwable $e) {
        dump(['THREW' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)]);
    }
})->group('probe');

it('PROBE 3: audit_log can be truncated despite the append-only triggers', function (): void {
    [$team] = $this->teamWithOwner();

    dump(['before' => DB::table('audit_log')->count()]);

    try {
        DB::statement('TRUNCATE TABLE audit_log');
        dump(['after TRUNCATE' => DB::table('audit_log')->count()]);
    } catch (Throwable $e) {
        dump(['truncate blocked' => $e::class, substr($e->getMessage(), 0, 160)]);
    }
})->group('probe');

it('PROBE 4: an invitation link signs you in as an existing credentialed account', function (): void {
    [$teamA, $ownerA] = $this->teamWithOwner();
    $this->enrollTwoFactor($ownerA);

    // The victim: an existing account with a password and 2FA, owner of team A.
    $victim = $ownerA->fresh();

    [$teamB, $ownerB] = $this->teamWithOwner();

    $role = App\Models\Role::query()->whereNull('team_id')
        ->where('key', App\Enums\SystemRole::TeamMember->value)->sole();

    $invitation = app(TeamContext::class)->runFor($teamB, fn () => app(App\Actions\Teams\InvitePersonToTeam::class)
        ->handle($teamB, $victim->email, $role, $ownerB));

    // Attacker holds only the token (a forwarded/leaked invite link).
    $token = null;
    Illuminate\Support\Facades\Mail::assertSent(App\Mail\TeamInvitationMail::class, function ($mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    $originalHash = $victim->password;

    $this->post('/invitations/'.$token, [
        'first_name' => 'Attacker',
        'last_name' => 'X',
        'password' => 'Sup3rSecret!Passw0rd',
    ]);

    dump([
        'authenticated as victim' => auth()->id() === $victim->getKey(),
        'victim id' => $victim->getKey(),
        'auth id' => auth()->id(),
        'password unchanged' => $victim->fresh()->password === $originalHash,
        'victim has 2FA' => $victim->fresh()->two_factor_confirmed_at !== null,
        'teams now reachable' => auth()->user()?->activeTeams()->pluck('id')->all(),
        'team A id' => $teamA->getKey(),
    ]);
})->group('probe');

it('PROBE 5: invitation matches email case-sensitively and duplicates the person', function (): void {
    [$teamB, $ownerB] = $this->teamWithOwner();

    $existing = Person::factory()->create(['email' => 'casey@example.com']);

    $role = App\Models\Role::query()->whereNull('team_id')
        ->where('key', App\Enums\SystemRole::TeamMember->value)->sole();

    app(TeamContext::class)->runFor($teamB, fn () => app(App\Actions\Teams\InvitePersonToTeam::class)
        ->handle($teamB, 'Casey@Example.com', $role, $ownerB));

    $token = null;
    Illuminate\Support\Facades\Mail::assertSent(App\Mail\TeamInvitationMail::class, function ($mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    $this->post('/invitations/'.$token, [
        'first_name' => 'Casey',
        'password' => 'Sup3rSecret!Passw0rd',
    ]);

    dump([
        'people rows for casey' => DB::table('people')->whereRaw('lower(email) = ?', ['casey@example.com'])->count(),
        'emails' => DB::table('people')->whereRaw('lower(email) = ?', ['casey@example.com'])->pluck('email')->all(),
    ]);
})->group('probe');
