<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Models\TeamMembership;

/**
 * S08's panel and S78's preferences (F12.4 · issue #101).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('folds a burst into one line', function (): void {
    /*
     * #101: *"twelve 'task assigned' notifications from one workflow
     * instantiation should read as one line, not twelve."* That burst is
     * exactly what attaching a workflow produces, and a panel that draws
     * twelve lines for it is a panel whose badge means "a workflow started".
     */
    Notification::factory()->count(12)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $this->deal->getKey(),
        'type' => NotificationType::TaskAssigned,
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->has('groups', 1)
            ->where('groups.0.count', 12)
            ->where('groups.0.summary', '12 tasks were assigned to you'));
});

it('never folds an override, because two of them are two facts', function (): void {
    /*
     * IA §7 makes an override legally distinct and `AdvanceWorkflow` writes
     * four artefacts for one. *"2 requirements were overridden"* is the
     * summary that stops somebody reading either.
     */
    Notification::factory()->count(2)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'deal_id' => $this->deal->getKey(),
        'type' => NotificationType::GateOverridden,
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('shows a person their notifications from every team they are in', function (): void {
    /*
     * #101: *"a person in two teams needs to know which one a notification
     * came from, and switching teams should not hide it."* A stager working
     * two agencies who is told at nine that a task is theirs must not lose it
     * by switching at ten.
     */
    [$other] = $this->teamWithMember($this->member);

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'summary' => 'From the first team',
    ]);

    /*
     * Written inside the other team's context — `BelongsToTeam`'s cross-tenant
     * guard is doing its job, and in production this row is written by a
     * worker running under `RunsForTeam` for that team.
     */
    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        Notification::factory()->create([
            'team_id' => $other->getKey(),
            'person_id' => $this->member->getKey(),
            'summary' => 'From the second team',
        ]);
    });

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('opens a deal in another team by switching to it first', function (): void {
    /*
     * Blocking finding #5, end to end. The panel reads across teams on purpose,
     * so a line can be about a deal the **resolved** team cannot see — and
     * Deal's team-scoped route binding turns a direct link into a 404 for
     * exactly the person the cross-team panel exists for.
     *
     * The assertion that matters is the session key: without the switch the
     * redirect is correct and the page it lands on is a 404, which is why
     * asserting only on the `Location` header would pass against the defect.
     */
    [$other] = $this->teamWithMember($this->member);

    $notification = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $other,
        function () use ($other): Notification {
            $deal = Deal::factory()->create(['team_id' => $other->getKey()]);

            return Notification::factory()->create([
                'team_id' => $other->getKey(),
                'person_id' => $this->member->getKey(),
                'deal_id' => $deal->getKey(),
            ]);
        },
    );

    // Still in the first team, which is the whole point.
    $this->get('/notifications/'.$notification->getKey().'/open')
        ->assertRedirect('/deals/'.$notification->deal_id.'/tasks')
        ->assertSessionHas(
            App\Http\Middleware\ResolveCurrentTeam::SESSION_KEY,
            $other->getKey(),
        );

    // Opening is the strongest signal there is that somebody has seen it.
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('will not switch a person into a team the platform has suspended', function (): void {
    /*
     * `Notification::forPerson()` now filters on the membership, so a revoked
     * one is no longer findable at all — covered by *"will not open a
     * notification from a team the person has left"* above.
     *
     * What is left for `open()`'s own `activeTeams()` check is the case the
     * membership cannot answer: the team is **suspended**. That is a platform
     * action against the team rather than a statement about this person, so
     * the row is still theirs and the switch still must not happen.
     */
    [$other] = $this->teamWithMember($this->member);

    $notification = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $other,
        function () use ($other): Notification {
            $deal = Deal::factory()->create(['team_id' => $other->getKey()]);

            return Notification::factory()->create([
                'team_id' => $other->getKey(),
                'person_id' => $this->member->getKey(),
                'deal_id' => $deal->getKey(),
            ]);
        },
    );

    $other->forceFill(['suspended_at' => now()])->save();

    $this->get('/notifications/'.$notification->getKey().'/open')
        ->assertRedirect()
        // Still in the team they were actually working in.
        ->assertSessionHas(
            App\Http\Middleware\ResolveCurrentTeam::SESSION_KEY,
            $this->team->getKey(),
        );
});

it('will not open somebody else’s notification', function (): void {
    $stranger = Person::factory()->create();

    $theirs = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'deal_id' => Deal::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
    ]);

    /*
     * 404 rather than 403, for the reason `TeamSwitchController` gives:
     * confirming a row exists is itself a disclosure.
     */
    $this->get('/notifications/'.$theirs->getKey().'/open')->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

it('stops showing a team’s notifications once the membership is revoked', function (): void {
    /*
     * Round 3 of review's first blocking finding, and the distinction it turns
     * on: `summary` is **snapshotted** at raise time, but `dealName` and
     * `teamName` are hydrated **live** on every load. So the docblock's claim
     * that the feed *"reads exactly the deals the person was already told
     * about"* was true of which rows and false of what they say — a former
     * member with an account in another team went on receiving team A's
     * current deal names indefinitely.
     *
     * The rename after the revocation is what makes this a leak rather than a
     * stale line: the name the feed returns is one that did not exist while
     * they were a member.
     */
    [$other] = $this->teamWithMember($this->member);

    $notification = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $other,
        function () use ($other): Notification {
            $deal = Deal::factory()->create([
                'team_id' => $other->getKey(),
                'name' => 'While they were a member',
            ]);

            return Notification::factory()->create([
                'team_id' => $other->getKey(),
                'person_id' => $this->member->getKey(),
                'deal_id' => $deal->getKey(),
            ]);
        },
    );

    // Both teams' lines while the membership stands.
    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 1));

    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        TeamMembership::query()
            ->where('team_id', $other->getKey())
            ->where('person_id', $this->member->getKey())
            ->update(['revoked_at' => now()]);

        Deal::query()->whereKey(
            Notification::query()->where('team_id', $other->getKey())->value('deal_id'),
        )->update(['name' => 'Renamed after they left']);
    });

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 0));

    // The badge counts the same rows, so it has to agree.
    expect(App\Queries\ShellCounts::for($this->member)['notifications'])->toBe(0);

    // And the row is still there — this is a read predicate, not a purge.
    expect(Notification::withoutTeamScope()->find($notification->getKey()))->not->toBeNull();
});

it('will not open a notification from a team the person has left', function (): void {
    /*
     * The same predicate, one route along. `open()` used to redirect anyway
     * and merely decline the team switch, which landed somebody on a 404
     * through Deal's team-scoped binding; now the row is not theirs to find.
     */
    [$other] = $this->teamWithMember($this->member);

    $notification = app(App\Support\Tenancy\TeamContext::class)->runFor(
        $other,
        function () use ($other): Notification {
            $deal = Deal::factory()->create(['team_id' => $other->getKey()]);

            return Notification::factory()->create([
                'team_id' => $other->getKey(),
                'person_id' => $this->member->getKey(),
                'deal_id' => $deal->getKey(),
            ]);
        },
    );

    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        TeamMembership::query()
            ->where('team_id', $other->getKey())
            ->where('person_id', $this->member->getKey())
            ->update(['revoked_at' => now()]);
    });

    $this->get('/notifications/'.$notification->getKey().'/open')->assertNotFound();
});

it('counts a folded burst by everything in it, not by what fits the popover', function (): void {
    /*
     * Round 3's second blocking finding. `PREVIEW` is a count of **lines**,
     * and passing it as `groupsFor()`'s row limit folded a burst inside an
     * eight-row window: the popover said *"5 tasks were assigned to you"* over
     * an event that produced twelve, while the full page said twelve.
     *
     * The consequence that matters is not the wrong number on its own. The
     * bell counts rows, `markRead()` posts the group's ids, so pressing it
     * dropped the badge by five and the line came back — the panel telling
     * somebody their action did not work.
     */
    $burst = Notification::factory()->count(12)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'type' => NotificationType::TaskAssigned->value,
        'deal_id' => $this->deal->getKey(),
    ]);

    $preview = app(App\Support\Notifications\NotificationFeed::class)->previewFor($this->member);
    $page = app(App\Support\Notifications\NotificationFeed::class)
        ->groupsFor($this->member, App\Support\Notifications\NotificationFeed::PAGE);

    expect($preview[0]['count'])->toBe($burst->count())
        // The two screens cannot disagree about what a line says, which is the
        // half a reader acts on.
        ->and($preview[0]['summary'])->toBe($page[0]['summary'])
        ->and($preview[0]['ids'])->toBe($page[0]['ids']);
});

it('still cuts the popover to its promised length', function (): void {
    /*
     * The other half: folding over the whole page rather than an eight-row
     * window must not turn the popover into the full list.
     */
    Notification::factory()->count(App\Support\Notifications\NotificationFeed::PREVIEW + 5)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        // A type that never folds, so every row is its own line.
        'type' => NotificationType::GateOverridden->value,
        'deal_id' => $this->deal->getKey(),
    ]);

    expect(app(App\Support\Notifications\NotificationFeed::class)->previewFor($this->member))
        ->toHaveCount(App\Support\Notifications\NotificationFeed::PREVIEW);
});

it('counts unread notifications on the shell badge', function (): void {
    /*
     * The badge is what this whole feature is for, and until round 4 of review
     * every server-side assertion about it was at **zero** — which a
     * `ShellCounts` that returned a constant zero would have satisfied. Read
     * across teams like the panel it sits above, and unread only.
     */
    [$other] = $this->teamWithMember($this->member);

    Notification::factory()->count(2)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'read_at' => null,
    ]);

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
        'read_at' => now(),
    ]);

    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        Notification::factory()->create([
            'team_id' => $other->getKey(),
            'person_id' => $this->member->getKey(),
            'read_at' => null,
        ]);
    });

    // Somebody else's, which must not be counted.
    $stranger = Person::factory()->create();

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'read_at' => null,
    ]);

    // Two here plus one in the other team; the read one and the stranger's
    // are excluded.
    expect(App\Queries\ShellCounts::for($this->member)['notifications'])->toBe(3);

    // And the shell actually carries it, which is the half a component reads.
    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.notifications', 3));
});

it('shows nobody else’s notifications', function (): void {
    $stranger = Person::factory()->create();

    TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'first_name' => 'Sam',
        'last_name' => 'Reilly',
        'email' => 'sam@example.test',
    ]);

    Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
        'summary' => 'Not for you',
    ]);

    $this->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 0));
});

it('marks one read without touching the rest', function (): void {
    $mine = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $other = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    /*
     * **A list, because a folded line stands for as many rows as it folded.**
     * The panel fires one request naming every id in the group rather than one
     * per id — measured at 11 of 12 aborted by Inertia's sync stream, each
     * survivor re-running the whole feed.
     */
    $this->post('/notifications/read', ['notifications' => [$mine->getKey()]])
        ->assertRedirect();

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();
});

it('refuses a malformed notifications body rather than 500ing on it', function (): void {
    /*
     * `array_filter()` on a scalar is a `TypeError`. The first version read
     * the key straight off the request, so `notifications=x` was a 500.
     */
    $this->post('/notifications/read', ['notifications' => 'not-a-list'])
        ->assertSessionHasErrors('notifications');
});

it('never widens a named list into “all of mine”', function (): void {
    /*
     * The absent-key branch means *"mark all read"*, so a present list that
     * filtered down to nothing used to become **mark everything read** — the
     * one thing on this route that cannot be undone. A list of junk is a 422,
     * not a silent broadening.
     */
    $mine = Notification::factory()->count(2)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    $this->post('/notifications/read', ['notifications' => ['', 'nonsense']])
        ->assertSessionHasErrors();

    expect(Notification::query()->forPerson($this->member)->unread()->count())
        ->toBe($mine->count());
});

it('will not let somebody mark another person’s notification read', function (): void {
    $stranger = Person::factory()->create();

    $theirs = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
    ]);

    $this->post('/notifications/read', ['notifications' => [$theirs->getKey()]])
        ->assertRedirect();

    // The predicate *is* the authorization: the update is scoped to the person
    // asking, so a stranger's id simply matches nothing.
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all read', function (): void {
    Notification::factory()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);

    /*
     * A stranger's row, because *"all"* has to mean all of **mine**. No ids
     * named is the branch that writes the most rows, and the predicate is the
     * only thing bounding it — there is no policy here to catch a mistake.
     */
    $stranger = Person::factory()->create();

    $theirs = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $stranger->getKey(),
    ]);

    $this->post('/notifications/read')->assertRedirect();

    expect(Notification::query()->forPerson($this->member)->unread()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});

it('offers S78 the channels somebody may actually choose', function (): void {
    /*
     * `in_app` is not offered — the panel is the record and cannot be switched
     * off — and `push` is not offered until #103 exists, because a switch that
     * does nothing is worse than an absent one.
     */
    $this->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Notifications')
            ->has('types', count(NotificationType::cases()))
            ->has('channels', 1)
            ->where('channels.0.value', 'email')
            ->has('comingSoon', 1));
});

it('saves a preference and a quiet-hours window', function (): void {
    $this->patch('/settings/notifications', [
        'channels' => [NotificationType::GateCleared->value => ['email']],
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '07:00',
    ])->assertRedirect();

    $preference = NotificationPreference::query()->sole();

    expect($preference->channels[NotificationType::GateCleared->value])->toBe(['email'])
        ->and($preference->hasQuietHours())->toBeTrue();
});

it('refuses half a quiet-hours window', function (): void {
    /*
     * The failure a half-set window produces is the worst kind: a person
     * believes they have set quiet hours and the sends go out anyway.
     */
    $this->patch('/settings/notifications', ['quiet_hours_start' => '21:00'])
        ->assertSessionHasErrors('quiet_hours_end');

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('refuses a channel nothing can deliver on', function (): void {
    $this->patch('/settings/notifications', [
        'channels' => [NotificationType::GateCleared->value => ['push']],
    ])->assertSessionHasErrors('channels.'.NotificationType::GateCleared->value.'.0');
});

it('turns a channel off, which is an empty array rather than an absent key', function (): void {
    /*
     * How somebody stops being emailed, and the one save shape a "does it
     * store what I chose" test misses: switching the last channel off sends
     * `[]`, not a key with something in it. The controller's `is_array()`
     * guard is what makes that different from *"this type was not in the
     * form"* — read as absent, the previous choice would survive the save and
     * the email would keep arriving from a screen showing the switch off.
     *
     * Asserted through `Notify` as well as through the row, because the
     * storage and the honouring are two different questions and only the
     * second is the one somebody is asking.
     */
    [$team, $member] = [$this->team, $this->member];

    $this->patch('/settings/notifications', [
        'channels' => [NotificationType::TaskAssigned->value => []],
    ])->assertRedirect();

    $preference = NotificationPreference::query()->sole();

    expect($preference->channels[NotificationType::TaskAssigned->value])->toBe([]);

    $written = app(App\Support\Notifications\Notify::class)->send(
        type: NotificationType::TaskAssigned,
        people: [$member],
        team: $team,
        summary: 'Order the survey',
    );

    // The panel keeps the record; nothing reaches out.
    expect($written[0]->channels)->toBe(['in_app'])
        ->and($written[0]->outboundChannels())->toBe([]);
});

it('refuses a quiet-hours window whose ends meet', function (): void {
    /*
     * `09:00 → 09:00` takes the non-wrapping branch in `holdUntil()`, where
     * `>= start && < end` can never be true — so it stores cleanly and means
     * *"never quiet"*, which is plausibly the opposite of what was intended.
     * A setting that does nothing while looking set is the failure S78 exists
     * to avoid.
     */
    $this->patch('/settings/notifications', [
        'quiet_hours_start' => '09:00',
        'quiet_hours_end' => '09:00',
    ])->assertSessionHasErrors('quiet_hours_end');

    expect(NotificationPreference::query()->count())->toBe(0);
});
