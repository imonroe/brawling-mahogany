<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Person;
use App\Support\Activity\RecordActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unified timeline (PRD §4.9 F9.4, §6.2, §7.7 · issue #50).
 */
it('defaults an event to internal', function (): void {
    // Issue #50: "`is_client_visible` defaults to false and a test proves it."
    // The client status page (Slice 4) reads this table filtered to visible
    // events, so the default is the boundary.
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $event = app(RecordActivity::class)->record(
        subject: $member,
        eventType: 'contact.logged',
        summary: 'Phone call',
        source: ActivitySource::Manual,
    );

    expect($event->is_client_visible)->toBeFalse();
});

it('defaults to internal at the column too', function (): void {
    // Not only in the service: a row inserted by anything else is internal.
    $default = collect(Schema::getColumns('activity_events'))
        ->firstWhere('name', 'is_client_visible')['default'] ?? null;

    expect($default)->toContain('false');
});

it('carries the actor and the team without being told', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $event = app(RecordActivity::class)->record(
        subject: $member,
        eventType: 'person.added',
        summary: 'Added to the team directory',
    );

    expect($event->team_id)->toBe($team->getKey())
        ->and($event->actor_person_id)->toBe($member->getKey())
        ->and($event->subject_type)->toBe((new Person)->getMorphClass());
});

it('leaves the actor empty when nothing human did it', function (): void {
    // A scheduled automation has no person behind it, and inventing one would
    // put a name on the timeline that never touched the record.
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $event = app(RecordActivity::class)->record(
        subject: $member,
        eventType: 'automation.fired',
        summary: 'Milestone email sent',
        source: ActivitySource::Automation,
    );

    expect($event->actor_person_id)->toBeNull();
});

it('reads one subject’s timeline in a single query', function (): void {
    // PRD §9's target is 500,000 events. The two queries that matter are one
    // subject's timeline and one team's recent activity, and both are indexed.
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    ActivityEvent::factory()->count(30)->create([
        'team_id' => $team->getKey(),
        'subject_type' => (new Person)->getMorphClass(),
        'subject_id' => $member->getKey(),
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $events = ActivityEvent::query()->forSubject($member)->limit(50)->get();

    expect($events)->toHaveCount(30)
        ->and($queries)->toBe(1);
});

it('indexes the two queries that matter', function (): void {
    $indexes = collect(Schema::getIndexes('activity_events'))->pluck('columns');

    expect($indexes)->toContain(['team_id', 'subject_type', 'subject_id', 'occurred_at'])
        ->and($indexes)->toContain(['team_id', 'occurred_at']);
});

/**
 * The deal an event belongs on (issue #81).
 *
 * `subject` answers *what this happened to* and `deal_id` answers *which deal
 * this belongs on*, and S26 is where the two come apart: a logged contact's
 * subject is the person and its deal is context. Every event with a deal
 * behind it has to carry it, or the deal's own timeline is missing whichever
 * ones somebody forgot.
 */
it('derives the deal from a subject that is one', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $deal = App\Models\Deal::factory()->create(['team_id' => $team->getKey()]);

    $event = app(RecordActivity::class)->record(
        subject: $deal,
        eventType: 'participant.added',
        summary: 'Added Emily as Seller',
    );

    // Not passed in. Seven of the nine deal-context call sites hand the deal
    // over as the subject already, and asking each of them to repeat it is the
    // shape of rule the next caller gets written without.
    expect($event->deal_id)->toBe($deal->getKey());

    unset($member);
});

it('carries the deal on an event whose subject is the workflow', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $deal = App\Models\Deal::factory()->create(['team_id' => $team->getKey()]);

    $workflow = App\Models\Workflow::factory()->create([
        'team_id' => $team->getKey(),
        'deal_id' => $deal->getKey(),
        'state' => App\Enums\WorkflowState::Active,
    ]);

    $first = App\Models\Stage::factory()->active()->create([
        'team_id' => $team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Listing Preparation',
        'sort_order' => 0,
    ]);

    App\Models\Stage::factory()->create([
        'team_id' => $team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Go Live',
        'sort_order' => 1,
    ]);

    $workflow->forceFill(['current_stage_id' => $first->getKey()])->save();

    app(App\Support\Workflow\AdvanceWorkflow::class)->handle($workflow->fresh(), $member);

    // One deal runs several workflows at once (F4.7). An advance subjected to
    // the workflow with no `deal_id` is an event the deal's own timeline and
    // the feed's deal filter cannot find.
    $event = ActivityEvent::query()->where('event_type', 'stage.advanced')->sole();

    expect($event->deal_id)->toBe($deal->getKey())
        ->and(ActivityEvent::query()->forDeal($deal)->pluck('id'))->toContain($event->getKey());
});

it('leaves the deal empty on an event that has nothing to do with one', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $event = app(RecordActivity::class)->record(
        subject: $member,
        eventType: 'person.added',
        summary: 'Added to the team directory',
    );

    expect($event->deal_id)->toBeNull();
});

it('indexes one deal’s timeline too', function (): void {
    $indexes = collect(Schema::getIndexes('activity_events'))->pluck('columns');

    expect($indexes)->toContain(['team_id', 'deal_id', 'occurred_at']);
});
