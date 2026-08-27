<?php

declare(strict_types=1);

use App\Enums\DocumentVisibility;
use App\Enums\ParticipantRole;
use App\Enums\StageState;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Document;
use App\Models\KeyDate;
use App\Models\Stage;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\StatusPage\IssueStatusPageLink;
use Inertia\Testing\AssertableInertia;

/**
 * S62 and S63 — what a client actually reads (IA §9 · issue #111).
 *
 * IA §9's three rules are the ones a screen breaks silently, so they are
 * asserted rather than remembered: no internal stage name, no instruction
 * aimed at the client, and no alarming word.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->client = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Okafor',
        'email' => 'dana@example.test',
    ]);

    DealParticipant::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'team_membership_id' => $this->client->getKey(),
        'participant_role' => ParticipantRole::Seller,
        'is_primary' => true,
    ]);

    $this->workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);
});

function stageOn(array $attributes): Stage
{
    return Stage::factory()->create([
        'team_id' => test()->team->getKey(),
        'workflow_id' => test()->workflow->getKey(),
        ...$attributes,
    ]);
}

/** Open the page as a client would, and hand back the props. */
function clientProps(): array
{
    $issued = app(IssueStatusPageLink::class)->issue(test()->deal, test()->client);

    auth()->logout();

    $session = (string) str(
        test()->get('/s/'.$issued->token)->headers->get('Location'),
    )->afterLast('/s/');

    $props = [];

    test()->get("/s/{$session}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return [$props, $session];
}

it('shows the milestone label and never the internal stage name', function (): void {
    stageOn([
        'name' => 'Chase lender',
        'sort_order' => 0,
        'state' => StageState::Complete,
        'is_milestone' => true,
        'milestone_label' => 'Your loan is approved',
        'actual_end' => '2026-08-20',
    ]);

    [$props] = clientProps();

    $rendered = json_encode($props['steps']);

    expect(collect($props['steps'])->pluck('label')->all())->toBe(['Your loan is approved'])
        // Internal names say things like "Chase lender" and "Nudge the other
        // agent", which are accurate, useful, and not for sharing.
        ->and($rendered)->not->toContain('Chase lender');
});

it('omits a stage nobody wrote a client label for', function (): void {
    stageOn(['name' => 'Nudge the other agent', 'sort_order' => 0, 'is_milestone' => false]);

    [$props] = clientProps();

    /*
     * Omitted rather than named from `stages.name`. The consequence is real
     * and correct: a workflow whose author labelled no stage shows an empty
     * timeline and the status card carries the page. That is better than a
     * page leaking the team's internal shorthand, and S41 is where somebody
     * fixes it.
     */
    expect($props['steps'])->toBe([])
        ->and(json_encode($props))->not->toContain('Nudge the other agent');
});

it('hides a skipped stage entirely', function (): void {
    stageOn([
        'name' => 'Inspection',
        'sort_order' => 0,
        'state' => StageState::Skipped,
        'is_milestone' => true,
        'milestone_label' => 'Inspection done',
        'skipped_reason' => 'Cash sale, no inspection',
    ]);

    stageOn([
        'name' => 'Closing',
        'sort_order' => 1,
        'is_milestone' => true,
        'milestone_label' => 'Sold',
    ]);

    [$props] = clientProps();

    /*
     * IA §7 makes Skip and Override legally distinct, and this is that
     * distinction arriving on the client surface: a stage that did not apply
     * to this deal is not a step this client's sale has. Showing it greyed
     * out would invite the question the page exists to prevent.
     */
    expect(collect($props['steps'])->pluck('label')->all())->toBe(['Sold']);
});

it('shows a blocked stage as happening now', function (): void {
    stageOn([
        'name' => 'Under contract',
        'sort_order' => 0,
        'state' => StageState::Blocked,
        'is_milestone' => true,
        'milestone_label' => 'You have an accepted offer',
    ]);

    [$props] = clientProps();

    expect($props['steps'][0]['position'])->toBe('now')
        ->and($props['steps'][0]['when'])->toBe('Happening now')
        // IA §8 and IA §9: `blocked` never reaches a client.
        ->and(json_encode($props))->not->toContain('blocked');
});

it('uses no alarming word anywhere on the page', function (): void {
    stageOn([
        'name' => 'Under contract',
        'sort_order' => 0,
        'state' => StageState::Blocked,
        'is_milestone' => true,
        'milestone_label' => 'You have an accepted offer',
    ]);

    // A deadline that has already passed, which internally reads "past due".
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => now()->subWeek()->toDateString(),
    ]);

    [$props] = clientProps();

    /*
     * The page's **own** props, not Inertia's shared bag. `errors` is always
     * present and always empty, and scanning it would have this test failing
     * on the framework rather than on the copy — which is the version that
     * gets an exclusion added until the exclusion is doing all the work.
     */
    $rendered = mb_strtolower((string) json_encode(array_intersect_key(
        $props,
        array_flip(['team', 'deal', 'status', 'steps', 'dates', 'contact']),
    )));

    /*
     * IA §9: *"blocked, failed, overdue, and error never reach the client. If
     * something is late, the agent handles it by phone."* The honest way to
     * keep that rule about a passed deadline is not to soften the word — it is
     * not to show the row.
     */
    foreach (['blocked', 'failed', 'overdue', 'past due', 'error', 'gate', 'requirement'] as $word) {
        expect($rendered)->not->toContain($word);
    }
});

it('shows only dates that are still ahead, and none the machine proposed', function (): void {
    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Closing',
        'date' => now()->addWeeks(2)->toDateString(),
    ]);

    KeyDate::factory()->pending()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Suggested appraisal',
        'date' => now()->addWeek()->toDateString(),
    ]);

    [$props] = clientProps();

    /*
     * A proposal is not a date (#116), and this is the surface where a wrong
     * one would do the most damage — a client plans around it.
     */
    expect(collect($props['dates'])->pluck('name')->all())->toBe(['Closing'])
        ->and(json_encode($props))->not->toContain('Suggested appraisal');
});

it('says there is nothing to do, in every status', function (): void {
    [$props] = clientProps();

    /*
     * Design System §9.6 is explicit that this is **not an empty state to be
     * designed later** — it is the default copy, present in every status,
     * because *"a client checks in during a quiet week and needs to leave
     * reassured rather than worried."*
     */
    expect($props['status']['reassurance'])->toContain('nothing you need to do');
});

it('lists only documents somebody deliberately made client-visible', function (): void {
    $shared = Document::factory()->create([
        'team_id' => $this->team->getKey(),
        'documentable_type' => (new Deal)->getMorphClass(),
        'documentable_id' => $this->deal->getKey(),
        'original_name' => 'Seller disclosure.pdf',
        'visibility' => DocumentVisibility::ClientVisible,
    ]);

    Document::factory()->create([
        'team_id' => $this->team->getKey(),
        'documentable_type' => (new Deal)->getMorphClass(),
        'documentable_id' => $this->deal->getKey(),
        'original_name' => 'Internal notes.pdf',
        'visibility' => DocumentVisibility::Internal,
    ]);

    [, $session] = clientProps();

    $this->get("/s/{$session}/documents")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($shared): void {
            $documents = $page->toArray()['props']['documents'];

            expect(collect($documents)->pluck('name')->all())->toBe(['Seller disclosure.pdf'])
                ->and(collect($documents)->pluck('id')->all())->toBe([$shared->getKey()])
                /*
                 * No `scan_state`, no category, no size. A badge reading
                 * *clean* over a photograph of a cheque would be believed, and
                 * *not scanned* is exactly the word IA §9 keeps out.
                 */
                ->and(json_encode($documents))->not->toContain('scan_state')
                ->and(json_encode($documents))->not->toContain('not_scanned');
        });
});

it('refuses a document from another deal, even with a live session', function (): void {
    $otherDeal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $elsewhere = Document::factory()->create([
        'team_id' => $this->team->getKey(),
        'documentable_type' => (new Deal)->getMorphClass(),
        'documentable_id' => $otherDeal->getKey(),
        'visibility' => DocumentVisibility::ClientVisible,
    ]);

    [, $session] = clientProps();

    /*
     * The check that is easiest to miss: without it, a client with a session
     * for one deal could name any client-visible document id in the system.
     */
    $this->get("/s/{$session}/documents/{$elsewhere->getKey()}")->assertNotFound();
});

it('refuses an internal document even on its own deal', function (): void {
    $internal = Document::factory()->create([
        'team_id' => $this->team->getKey(),
        'documentable_type' => (new Deal)->getMorphClass(),
        'documentable_id' => $this->deal->getKey(),
        'visibility' => DocumentVisibility::Internal,
    ]);

    [, $session] = clientProps();

    $this->get("/s/{$session}/documents/{$internal->getKey()}")->assertNotFound();
});
