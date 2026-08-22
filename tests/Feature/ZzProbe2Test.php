<?php

declare(strict_types=1);

use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('PROBE 4b: invitation accept for an existing credentialed account', function (): void {
    [$teamA, $ownerA] = $this->teamWithOwner();
    $this->enrollTwoFactor($ownerA);
    $victim = $ownerA->fresh();

    [$teamB, $ownerB] = $this->teamWithOwner();

    $role = App\Models\Role::query()->whereNull('team_id')
        ->where('key', App\Enums\SystemRole::TeamMember->value)->sole();

    $invitation = app(TeamContext::class)->runFor($teamB, fn () => app(App\Actions\Teams\InvitePersonToTeam::class)
        ->handle($teamB, $victim->email, $role, $ownerB));

    $token = null;
    Mail::assertSent(App\Mail\TeamInvitationMail::class, function ($mail) use (&$token) {
        $token = $mail->token ?? null;

        return true;
    });
    dump(['token' => $token, 'invite pending' => $invitation->fresh()->isPending()]);

    app(TeamContext::class)->set(null);

    $res = $this->post('/invitations/'.$token, [
        'first_name' => 'Attacker',
        'last_name' => 'X',
        'password' => 'Sup3rSecret!Passw0rd', 'password_confirmation' => 'Sup3rSecret!Passw0rd',
    ]);

    dump([
        'status' => $res->getStatusCode(),
        'location' => $res->headers->get('Location'),
        'errors' => json_encode(session('errors')),
        'auth id' => auth()->id(),
        'victim id' => $victim->getKey(),
        'accepted' => $invitation->fresh()->accepted_at?->toIso8601String(),
        'memberships' => DB::table('team_memberships')->where('person_id', $victim->getKey())->count(),
    ]);

    // Follow-on: is the session usable as the victim?
    $follow = $this->get('/settings/profile');
    dump(['GET /settings/profile as?' => $follow->getStatusCode(), 'auth after' => auth()->id()]);
})->group('probe');
