<?php

declare(strict_types=1);

use App\Enums\ContactImportSource;
use App\Enums\ContactImportState;
use App\Models\ContactImport;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('PROBE 5b: invitation email matching is case sensitive', function (): void {
    [$teamB, $ownerB] = $this->teamWithOwner();
    $existing = App\Models\Person::factory()->create(['email' => 'casey@example.com', 'password' => null]);

    $role = App\Models\Role::query()->whereNull('team_id')
        ->where('key', App\Enums\SystemRole::TeamMember->value)->sole();

    app(TeamContext::class)->runFor($teamB, fn () => app(App\Actions\Teams\InvitePersonToTeam::class)
        ->handle($teamB, 'Casey@Example.com', $role, $ownerB));

    $token = null;
    Mail::assertSent(App\Mail\TeamInvitationMail::class, function ($mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    app(TeamContext::class)->set(null);

    $res = $this->post('/invitations/'.$token, [
        'first_name' => 'Casey', 'password' => 'Sup3rSecret!Passw0rd', 'password_confirmation' => 'Sup3rSecret!Passw0rd',
    ]);

    dump([
        'status' => $res->getStatusCode(),
        'rows for casey (case-insensitive)' => DB::table('people')->whereRaw('lower(email) = ?', ['casey@example.com'])->count(),
        'emails' => DB::table('people')->whereRaw('lower(email) = ?', ['casey@example.com'])->pluck('email')->all(),
    ]);
})->group('probe');

it('PROBE 6: the S32 form 500s when a person has no email', function (): void {
    [$team, $person] = $this->teamWithMember();
    $this->actingAs($person)->withSession([App\Http\Middleware\ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);

    $this->withoutExceptionHandling();
    try {
        $res = $this->post('/people', [
            'first_name' => 'Phoneonly', 'email' => '', 'phone' => '5551234',
            'status' => App\Enums\PersonLifecycleState::Lead->value,
        ]);
        dump(['status' => $res->getStatusCode()]);
    } catch (Throwable $e) {
        dump(['THREW' => $e::class, 'msg' => substr($e->getMessage(), 0, 120)]);
    }
})->group('probe');

it('PROBE 7: a phone-only CSV row fails to import', function (): void {
    [$team, $person] = $this->teamWithMember();
    $this->withTeam($team);

    $csv = "First Name,Last Name,Email,Phone\nNoEmail,Person,,555-0000\nHas,Email,has@example.com,555-1111\n";

    $import = ContactImport::query()->create([
        'requested_by_person_id' => $person->getKey(),
        'source' => ContactImportSource::Csv,
        'state' => ContactImportState::Pending,
        'original_filename' => 'c.csv',
        'disk_path' => 'imports/c.csv',
    ]);
    Illuminate\Support\Facades\Storage::put('imports/c.csv', $csv);

    (new App\Jobs\ParseContactImport($import->getKey()))->forTeam($team->getKey())
        ->handle(app(App\Support\Import\ContactParserFactory::class));

    $import->refresh();
    dump(['state' => $import->state->value, 'preview' => $import->preview, 'failures' => $import->failures]);

    (new App\Jobs\CommitContactImport($import->getKey()))->forTeam($team->getKey())
        ->handle(app(App\Support\Activity\RecordActivity::class), app(App\Support\Audit\AuditLogger::class));

    $import->refresh();
    dump(['summary' => $import->summary, 'failures after' => $import->failures]);
})->group('probe');

it('PROBE 8: the audit log stores raw IP addresses', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->withTeam($team);
    app(App\Support\Audit\AuditLogger::class)->record(action: 'probe.test', teamId: $team->getKey());
    dump(['ips' => DB::table('audit_log')->whereNotNull('ip')->pluck('ip')->unique()->values()->all()]);
})->group('probe');

it('PROBE 9: re-committing a failed import is impossible / stuck state', function (): void {
    [$team, $person] = $this->teamWithMember();
    $this->withTeam($team);
    $import = ContactImport::query()->create([
        'requested_by_person_id' => $person->getKey(),
        'source' => ContactImportSource::Csv,
        'state' => ContactImportState::Importing,
        'original_filename' => 'c.csv',
    ]);
    $this->actingAs($person)->withSession([App\Http\Middleware\ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);
    $res = $this->post('/people/import/'.$import->getKey(), ['actions' => []]);
    dump(['recommit status' => $res->getStatusCode()]);
})->group('probe');
