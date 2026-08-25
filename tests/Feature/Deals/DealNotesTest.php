<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Support\Tenancy\TeamContext;

/**
 * F4.11 — notes, internal by default (#72).
 *
 * *"Internal by default is the whole feature. The default must never be
 * 'visible', and the toggle must never be sticky across notes — an agent who
 * made one note client-visible last Tuesday must not silently publish the next
 * one."* So most of this file is about the absence of a value rather than the
 * presence of one.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

it('writes a note as an internal timeline entry', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/notes", [
        'body' => 'Sellers are away until the 12th; inspection needs the lockbox code.',
    ])->assertRedirect();

    $note = ActivityEvent::query()->where('event_type', 'note.added')->sole();

    expect($note->is_client_visible)->toBeFalse()
        ->and($note->source)->toBe(ActivitySource::Manual->value)
        // The note *is* the summary: there is no elsewhere for it to describe.
        ->and($note->summary)->toBe('Sellers are away until the 12th; inspection needs the lockbox code.')
        ->and($note->deal_id)->toBe($this->deal->getKey())
        ->and($note->actor_person_id)->toBe($this->member->getKey());
});

it('keeps a note internal when the checkbox is not sent at all', function (): void {
    /*
     * The case that matters, and the one a `required` rule would have broken.
     * An unchecked HTML checkbox sends **nothing** — so "internal by default"
     * has to hold for an absent field, not merely for a false one.
     */
    $this->post("/deals/{$this->deal->getKey()}/notes", ['body' => 'Internal thought.'])
        ->assertRedirect();

    expect(ActivityEvent::query()->where('event_type', 'note.added')->sole()->is_client_visible)
        ->toBeFalse();
});

it('publishes a note only when the request says so', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/notes", [
        'body' => 'Your inspection is booked for Thursday morning.',
        'is_client_visible' => true,
    ])->assertRedirect();

    expect(ActivityEvent::query()->where('event_type', 'note.added')->sole()->is_client_visible)
        ->toBeTrue();
});

it('never carries the toggle from one note to the next', function (): void {
    /*
     * The sticky-toggle failure, at the layer that can actually be tested from
     * here: nothing about the previous note may influence this one. The
     * browser half — a dialog that resets on every open, including after a
     * cancel — is pinned in `tests/js/addNoteDialog.test.ts`.
     */
    $this->post("/deals/{$this->deal->getKey()}/notes", [
        'body' => 'Published on purpose, this once.',
        'is_client_visible' => true,
    ])->assertRedirect();

    $this->post("/deals/{$this->deal->getKey()}/notes", [
        'body' => 'And this one is nobody else’s business.',
    ])->assertRedirect();

    $notes = ActivityEvent::query()
        ->where('event_type', 'note.added')
        ->orderBy('created_at')
        ->get();

    expect($notes)->toHaveCount(2)
        ->and($notes[0]->is_client_visible)->toBeTrue()
        ->and($notes[1]->is_client_visible)->toBeFalse();
});

it('refuses an empty note', function (): void {
    $this->post("/deals/{$this->deal->getKey()}/notes", ['body' => '  '])
        ->assertSessionHasErrors('body');

    expect(ActivityEvent::query()->where('event_type', 'note.added')->count())->toBe(0);
});

it('shows a note on the deal it was written on', function (): void {
    // The reader half: a note nothing renders is a note nobody wrote.
    $this->post("/deals/{$this->deal->getKey()}/notes", ['body' => 'Lockbox code is with the office.'])
        ->assertRedirect();

    $this->get("/deals/{$this->deal->getKey()}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $summaries = collect($page->toArray()['props']['activity'])->pluck('summary');

            expect($summaries)->toContain('Lockbox code is with the office.');
        });
});

it('keeps a note inside its own team', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $theirs = app(TeamContext::class)->runFor(
        $otherTeam,
        fn (): Deal => Deal::factory()->create(['team_id' => $otherTeam->getKey()]),
    );

    $this->post("/deals/{$theirs->getKey()}/notes", ['body' => 'Not mine to write on.'])
        ->assertNotFound();

    expect(ActivityEvent::withoutTeamScope()->where('event_type', 'note.added')->count())->toBe(0);
});
