<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\StageTemplate;
use App\Models\TaskTemplate;
use App\Models\TemplatePack;
use App\Models\WorkflowTemplate;
use App\Support\Templates\PackFile;
use App\Support\Tenancy\TeamContext;

/**
 * `packs:export` and `packs:import` (#87 · ADR 0003's shape, one module over).
 *
 * The console is the surface on purpose. #87's loop runs between a staging box
 * and the repository — seed a draft, let somebody mark it up on S41, take the
 * file back and commit it — and every step of that is an operator at a
 * terminal, not a screen. The same reasoning `invitation:link` and
 * `auth:reset-link` record: a thing an operator does belongs where an operator
 * is.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->path = storage_path('framework/testing/pack.json');

    @unlink($this->path);
});

afterEach(function (): void {
    @unlink($this->path);
});

function exportableTemplate(): WorkflowTemplate
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team): WorkflowTemplate {
        $template = WorkflowTemplate::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Listing to Close',
        ]);

        $stage = StageTemplate::factory()->create([
            'workflow_template_id' => $template->getKey(),
            'name' => 'Pre-listing',
            'owner_role' => 'Agent',
            'sort_order' => 0,
        ]);

        TaskTemplate::factory()->create([
            'stage_template_id' => $stage->getKey(),
            'title' => 'Book the photographer',
            'owner_role' => 'Transaction coordinator',
            'is_required' => true,
            'due_offset_days' => -2,
            'sort_order' => 0,
        ]);

        return $template;
    });
}

it('refuses to guess what to export', function (): void {
    $this->artisan('packs:export')
        ->expectsOutputToContain('Name one thing to export')
        ->assertFailed();
});

it('writes a template to a file an import can read', function (): void {
    $template = exportableTemplate();

    $this->artisan('packs:export', [
        '--template' => $template->getKey(),
        '--output' => $this->path,
    ])->assertSuccessful();

    expect(file_exists($this->path))->toBeTrue();

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

    expect($document['formatVersion'])->toBe(PackFile::VERSION)
        ->and($document['workflows'][0]['stages'][0]['ownerRole'])->toBe('Agent')
        ->and($document['workflows'][0]['stages'][0]['tasks'][0]['isRequired'])->toBeTrue()
        ->and($document['workflows'][0]['stages'][0]['tasks'][0]['dueOffsetDays'])->toBe(-2);

    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])
        ->assertSuccessful();

    app(TeamContext::class)->runWithoutScope(function (): void {
        $pack = TemplatePack::query()->where('slug', 'listing-to-close')->sole();

        expect(WorkflowTemplate::query()
            ->where('template_pack_id', $pack->getKey())
            ->where('name', 'Listing to Close')
            ->exists())->toBeTrue();
    });
});

it('names the template when more than one answers to the same name', function (): void {
    exportableTemplate();
    exportableTemplate();

    $this->artisan('packs:export', ['--template' => 'Listing to Close'])
        ->expectsOutputToContain('names more than one template')
        ->assertFailed();
});

it('refuses to guess a destination', function (): void {
    $template = exportableTemplate();

    $this->artisan('packs:export', ['--template' => $template->getKey(), '--output' => $this->path]);

    /*
     * Naming one is required rather than defaulted: the two differ in blast
     * radius by every team on the box, and *"I forgot the flag"* must not be
     * the difference.
     */
    $this->artisan('packs:import', ['file' => $this->path])
        ->expectsOutputToContain('Name one destination')
        ->assertFailed();

    $this->artisan('packs:import', [
        'file' => $this->path,
        '--as-pack' => true,
        '--team' => $this->team->slug,
    ])->expectsOutputToContain('Name one destination')->assertFailed();
});

it('says what is wrong with a file rather than throwing at the operator', function (): void {
    file_put_contents($this->path, json_encode(['formatVersion' => 99, 'pack' => [], 'workflows' => []]));

    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])
        ->expectsOutputToContain('not a pack this product can read')
        ->assertFailed();
});

it('refuses a file that is not there, and one that is not JSON', function (): void {
    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])
        ->expectsOutputToContain('no readable file')
        ->assertFailed();

    file_put_contents($this->path, 'not json at all');

    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])
        ->expectsOutputToContain('not valid JSON')
        ->assertFailed();
});

it('imports into a team, and records that somebody did', function (): void {
    $template = exportableTemplate();

    $this->artisan('packs:export', ['--template' => $template->getKey(), '--output' => $this->path]);

    $this->artisan('packs:import', ['file' => $this->path, '--team' => $this->team->slug])
        ->assertSuccessful();

    app(TeamContext::class)->runFor($this->team, function (): void {
        // Two now: the one exported, and the one imported beside it. An import
        // creates, it does not sync — importing twice gives two templates, the
        // way copying twice does.
        expect(WorkflowTemplate::query()
            ->where('team_id', $this->team->getKey())
            ->where('name', 'Listing to Close')
            ->count())->toBe(2);
    });

    $entry = AuditEntry::query()->where('action', 'templates.imported')->sole();

    expect($entry->team_id)->toBe($this->team->getKey());
});

it('refuses a team it has never heard of', function (): void {
    $template = exportableTemplate();

    $this->artisan('packs:export', ['--template' => $template->getKey(), '--output' => $this->path]);

    $this->artisan('packs:import', ['file' => $this->path, '--team' => 'no-such-team'])
        ->expectsOutputToContain('No team has the slug')
        ->assertFailed();
});

it('seeds every pack file that is there, twice, with the same result', function (): void {
    /*
     * `database/packs/` is empty today because #87 is blocked on #11's
     * content, so the seeder is exercised against a directory it can actually
     * be given rather than against nothing — the mechanism is what this test
     * is for, and the mechanism is what #87 asks to be built ahead of the
     * content.
     */
    $template = exportableTemplate();

    $this->artisan('packs:export', ['--template' => $template->getKey(), '--output' => $this->path]);

    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])->assertSuccessful();
    $this->artisan('packs:import', ['file' => $this->path, '--as-pack' => true])->assertSuccessful();

    app(TeamContext::class)->runWithoutScope(function (): void {
        expect(TemplatePack::query()->where('slug', 'listing-to-close')->count())->toBe(1);

        $pack = TemplatePack::query()->where('slug', 'listing-to-close')->sole();

        expect(WorkflowTemplate::query()->where('template_pack_id', $pack->getKey())->count())->toBe(1);
    });

    expect(AuditEntry::query()->where('action', 'template_pack.imported')->count())->toBe(2);
});
