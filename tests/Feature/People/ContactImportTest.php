<?php

declare(strict_types=1);

use App\Enums\ContactImportState;
use App\Enums\PersonLifecycleState;
use App\Models\ContactImport;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Import\CsvContactParser;
use App\Support\Import\GoogleContactsParser;
use App\Support\Import\VCardContactParser;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * S33 — contact import (PRD §4.2 F2.8).
 *
 * *"Nobody retypes a client list."* The screen's real work is the middle:
 * showing what will merge and what will be created, letting somebody change
 * it, and surviving a file with a bad row in it.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

it('parses a CSV, previews it, and writes nothing until told to', function (): void {
    $csv = <<<'CSV'
    First Name,Last Name,E-mail 1 - Value,Phone 1 - Value
    Claire,Nakamura,claire@example.test,3035550100
    Lee,Okonkwo,lee@example.test,3035550101
    CSV;

    $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertRedirect();

    $import = ContactImport::query()->sole();

    expect($import->state)->toBe(ContactImportState::AwaitingReview)
        ->and($import->preview)->toHaveCount(2)
        ->and($import->preview[0]['action'])->toBe('create')
        // Nothing is in the directory yet. S33: "let the user change it
        // before anything is written."
        ->and(TeamMembership::query()
            ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
            ->exists())->toBeFalse();

    $this->post("/people/import/{$import->getKey()}", [
        'actions' => [
            (string) $import->preview[0]['row'] => 'create',
            (string) $import->preview[1]['row'] => 'create',
        ],
    ])->assertRedirect();

    $import->refresh();

    expect($import->state)->toBe(ContactImportState::Completed)
        ->and($import->summary['created'])->toBe(2)
        ->and(Person::query()->where('email', 'claire@example.test')->exists())->toBeTrue();

    // Issue #49: imported people default to `lead`.
    $membership = TeamMembership::query()
        ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
        ->sole();

    expect($membership->status)->toBe(PersonLifecycleState::Lead);
});

it('imports the good rows and reports the bad one by number', function (): void {
    // "Row 340 is malformed — import the other 339 and report row 340
    // specifically."
    $csv = "First Name,Last Name,Email\n"
        ."Claire,Nakamura,claire@example.test\n"
        .",,\n"
        ."Broken,Row,not-an-email\n"
        ."Lee,Okonkwo,lee@example.test\n";

    $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ]);

    $import = ContactImport::query()->sole();

    // The blank row is skipped silently; the malformed address is reported.
    expect($import->preview)->toHaveCount(2)
        ->and($import->failures)->toHaveCount(1)
        ->and($import->failures[0]['row'])->toBe(4);

    // And the reason names the problem without quoting the value (PRD §9).
    expect($import->failures[0]['reason'])->not->toContain('not-an-email');
});

it('creates nothing new when the same file is imported twice', function (): void {
    $csv = "First Name,Email\nClaire,claire@example.test\n";

    $importFile = fn () => $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ]);

    $importFile();

    $first = ContactImport::query()->sole();

    $this->post("/people/import/{$first->getKey()}", [
        'actions' => [(string) $first->preview[0]['row'] => 'create'],
    ]);

    $importFile();

    $second = ContactImport::query()->latest()->first();

    // The second pass already knows they are there.
    expect($second->preview[0]['action'])->toBe('merge');

    $this->post("/people/import/{$second->getKey()}", []);

    expect(Person::query()->where('email', 'claire@example.test')->count())->toBe(1)
        ->and(TeamMembership::query()
            ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
            ->count())->toBe(1);
});

it('skips a duplicate inside one file', function (): void {
    // The commonest messy-CSV case, and the commonest bug.
    $csv = "First Name,Email\nClaire,claire@example.test\nClaire,CLAIRE@example.test\n";

    $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ]);

    $import = ContactImport::query()->sole();

    expect(collect($import->preview)->pluck('action')->all())->toBe(['create', 'skip']);
});

it('says so when no column looks like a name', function (): void {
    $csv = "Widget,Quantity\nSprocket,4\n";

    $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('inventory.csv', $csv),
    ]);

    $import = ContactImport::query()->sole();

    expect($import->preview)->toBe([])
        ->and($import->failures[0]['reason'])->toContain('No column is mapped to a name');
});

it('reads the header shapes exports actually produce', function (): void {
    $parser = new CsvContactParser;

    // Google, Apple, and a spreadsheet somebody typed.
    foreach ([
        "Given Name,Family Name,E-mail 1 - Value\nClaire,Nakamura,claire@example.test\n",
        "First Name,Last Name,Email\nClaire,Nakamura,claire@example.test\n",
        "first,last,email\nClaire,Nakamura,claire@example.test\n",
    ] as $csv) {
        $result = $parser->parse($csv);

        expect($result['contacts'])->toHaveCount(1)
            ->and($result['contacts'][0]->firstName)->toBe('Claire')
            ->and($result['contacts'][0]->email)->toBe('claire@example.test');
    }
});

it('survives the byte order mark Excel writes', function (): void {
    // Without stripping it the first header matches no alias and maps nothing.
    $result = (new CsvContactParser)->parse("\xEF\xBB\xBFFirst Name,Email\nClaire,claire@example.test\n");

    expect($result['contacts'])->toHaveCount(1);
});

it('splits a single name column', function (): void {
    $result = (new CsvContactParser)->parse("Name,Email\nClaire Nakamura,claire@example.test\n");

    expect($result['contacts'][0]->firstName)->toBe('Claire')
        ->and($result['contacts'][0]->lastName)->toBe('Nakamura');
});

it('reads a vCard, folded lines and all', function (): void {
    $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Nakamura;Claire;;;\r\nFN:Claire Nakamura\r\n"
        ."EMAIL;TYPE=INTERNET:claire@exam\r\n ple.test\r\nTEL;TYPE=CELL:+13035550100\r\nEND:VCARD\r\n";

    $result = (new VCardContactParser)->parse($vcard);

    expect($result['contacts'])->toHaveCount(1)
        ->and($result['contacts'][0]->firstName)->toBe('Claire')
        ->and($result['contacts'][0]->lastName)->toBe('Nakamura')
        // RFC 6350 §3.2 line unfolding: without it the address is truncated.
        ->and($result['contacts'][0]->email)->toBe('claire@example.test');
});

it('takes Google’s primary value rather than the first one it finds', function (): void {
    $json = json_encode([
        'connections' => [
            [
                'names' => [['givenName' => 'Claire', 'familyName' => 'Nakamura']],
                'emailAddresses' => [
                    ['value' => 'old@example.test'],
                    ['value' => 'claire@example.test', 'metadata' => ['primary' => true]],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = (new GoogleContactsParser)->parse($json);

    expect($result['contacts'][0]->email)->toBe('claire@example.test');
});

it('deletes the uploaded file once the import has run', function (): void {
    $this->post('/people/import', [
        'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', "First Name,Email\nClaire,claire@example.test\n"),
    ]);

    $import = ContactImport::query()->sole();
    $path = $import->disk_path;

    expect(Storage::exists($path))->toBeTrue();

    $this->post("/people/import/{$import->getKey()}", []);

    // The upload is a copy of somebody's whole client list; it has done its
    // job by the time the rows are in.
    expect(Storage::exists($path))->toBeFalse()
        ->and($import->fresh()->disk_path)->toBeNull();
});

it('never imports into another team', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $import = app(TeamContext::class)->runFor($this->team, fn () => ContactImport::factory()->create([
        'team_id' => $this->team->getKey(),
        'state' => ContactImportState::AwaitingReview,
        'preview' => [[
            'row' => 2,
            'first_name' => 'Claire',
            'last_name' => null,
            'email' => 'claire@example.test',
            'phone' => null,
            'action' => 'create',
        ]],
    ]));

    // The job carries its own team (issue #49), so it does not matter what
    // context the worker happened to pick it up in.
    app(TeamContext::class)->runFor($otherTeam, function () use ($import): void {
        (new App\Jobs\CommitContactImport($import->getKey()))
            ->forTeam($import->team_id)
            ->handle(app(App\Support\Activity\RecordActivity::class), app(App\Support\Audit\AuditLogger::class));
    });

    expect(TeamMembership::withoutTeamScope()
        ->where('team_id', $otherTeam->getKey())
        ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
        ->exists())->toBeFalse()
        ->and(TeamMembership::withoutTeamScope()
            ->where('team_id', $this->team->getKey())
            ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
            ->exists())->toBeTrue();
});

it('refuses an import from somebody without the permission', function (): void {
    $contact = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($contact): void {
        $membership = TeamMembership::factory()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $contact->getKey(),
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
        );
    });

    $this->actingAsPerson($contact, $this->team);

    $this->get('/people/import')->assertForbidden();
});
