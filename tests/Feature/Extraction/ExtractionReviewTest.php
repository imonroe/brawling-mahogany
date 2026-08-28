<?php

declare(strict_types=1);

use App\Enums\ExtractedFieldReviewState;
use App\Enums\KeyDateSource;
use App\Enums\OffsetBasis;
use App\Enums\TaskSource;
use App\Models\Deal;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\KeyDate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S66 and S67 — the one control between a model and a live calendar (#116, #117).
 *
 * PRD §6.2, and it is the sentence the whole slice turns on:
 *
 * > **Nothing reaches `key_dates` or `tasks` except through a confirmed row
 * > here.**
 *
 * `tests/Unit/ExtractionConfirmationPathTest.php` holds the *structural* half —
 * one caller of each extracted entry point, read out of the source. This file
 * holds the behavioural half, which is a different claim: that the door exists,
 * that walking through it writes everything F10.4 asks for, and that a proposal
 * nobody confirmed has created nothing at all.
 *
 * Everything goes through the real routes. The policy split is half the feature
 * — starting an extraction is `deals.manage` and accepting what comes back is
 * `extraction.confirm` — and a test that called `ConfirmExtractedField` directly
 * would assert none of it.
 */
beforeEach(function (): void {
    $this->freezeAt('2026-07-01 12:00:00');

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->extraction = Extraction::factory()->contract()->complete()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);
});

/** A pending proposal on this deal's extraction. */
function proposalOn(Extraction $extraction, callable $shape): ExtractedField
{
    return $shape(ExtractedField::factory())->create([
        'team_id' => $extraction->team_id,
        'extraction_id' => $extraction->getKey(),
    ]);
}

function confirmUrl(Deal $deal, Extraction $extraction, ExtractedField $field): string
{
    return "/deals/{$deal->getKey()}/extractions/{$extraction->getKey()}/fields/{$field->getKey()}";
}

it('creates nothing until somebody confirms it, and exactly one thing when they do', function (): void {
    /*
     * The invariant, asserted as a **pair on one fixture**. The first half is
     * the one that would pass vacuously on an empty database, which is why the
     * proposal is built first and the count taken before anything is pressed —
     * docs/Testing.md: *"a `0` or a `null` is the answer a broken feature gives
     * too."*
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Inspection Objection Deadline', '2026-07-25')
        ->critical());

    expect($field->isPending())->toBeTrue()
        ->and(KeyDate::query()->where('deal_id', $this->deal->getKey())->count())->toBe(0);

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    $date = KeyDate::query()->where('deal_id', $this->deal->getKey())->sole();

    /*
     * `source` and `confirmed_by`/`confirmed_at` together. #106 shipped the
     * columns and `KeyDate::scopeConfirmed()` reads both — *"a date typed by
     * hand is confirmed by having been typed and carries no timestamp"* — so a
     * writer that set the source and not the timestamp would produce a row every
     * count and every reminder sweep in the product silently skips.
     */
    expect($date->name)->toBe('Inspection Objection Deadline')
        ->and($date->date->toDateString())->toBe('2026-07-25')
        ->and($date->source)->toBe(KeyDateSource::Extracted)
        ->and($date->is_critical)->toBeTrue()
        ->and($date->confirmed_by)->toBe($this->member->getKey())
        ->and($date->confirmed_at)->not->toBeNull()
        ->and($date->isPending())->toBeFalse();
});

it('records who accepted what, and writes it to the audit log', function (): void {
    /*
     * F10.4 asks for *"what the human changed"*, and #118 calls it *"the
     * valuable one … simultaneously the audit trail, the quality metric, and the
     * input to improving the prompt"*.
     *
     * `final_value` is written even when it equals the proposal, and that is the
     * assertion rather than an incidental: a null there would make *"confirmed
     * unchanged"* and *"never reviewed"* the same row, and the 85%-without-edit
     * target is exactly the difference between them.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Closing Date', '2026-08-28'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    $field->refresh();

    expect($field->review_state)->toBe(ExtractedFieldReviewState::Confirmed)
        ->and($field->final_value)->toBe('2026-08-28')
        ->and($field->reviewed_by)->toBe($this->member->getKey())
        ->and($field->reviewed_at)->not->toBeNull()
        ->and($field->created_record_id)->not->toBeNull();

    /*
     * PRD §9 lists extraction reviews among the six things the audit log must
     * cover. The entry names the extraction, so the model and prompt version
     * behind the proposal are one join away — which is the only reason the
     * scorecard #118 asks for is answerable from the log at all.
     */
    $entry = DB::table('audit_log')->where('action', 'extraction.field_confirmed')->sole();

    /** @var array<string, mixed> $after */
    $after = json_decode((string) $entry->after, true, flags: JSON_THROW_ON_ERROR);

    expect($entry->actor_person_id)->toBe($this->member->getKey())
        ->and($after['final_value'])->toBe('2026-08-28')
        ->and($after['extraction_id'])->toBe($this->extraction->getKey())
        ->and($after['field_type'])->toBe('key_date');
});

it('marks a value the reviewer changed as edited, not as confirmed', function (): void {
    /*
     * The distinction #118 calls the valuable one. `confirmed` and `edited` both
     * accepted the proposal into the record — `ExtractedField` says so — and only
     * one of them agreed with what the model said. Folding them together would
     * make the quality metric unmeasurable and the audit trail unable to answer
     * *"did a person change this"*.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Closing Date', '2026-08-28'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field), ['value' => '2026-09-04'])
        ->assertRedirect();

    $field->refresh();

    expect($field->review_state)->toBe(ExtractedFieldReviewState::Edited)
        ->and($field->proposed_value)->toBe('2026-08-28')
        ->and($field->final_value)->toBe('2026-09-04');

    // The record carries what the human settled on, never the proposal.
    expect(KeyDate::query()->sole()->date->toDateString())->toBe('2026-09-04');

    expect(DB::table('audit_log')->where('action', 'extraction.field_edited')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'extraction.field_confirmed')->count())->toBe(0);
});

it('keeps a discarded proposal and creates nothing from it', function (): void {
    /*
     * #117: rejection is *"the expected common case rather than the exception"*
     * for an inspection report, so it is an ordinary outcome with an ordinary
     * record — **not** a deletion. The row stays because #118's quality question
     * is *what did the model get wrong*, and a rejected proposal is the clearest
     * possible answer to it.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Possession Date', '2026-08-28'));

    $this->delete(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    $field->refresh();

    expect($field->review_state)->toBe(ExtractedFieldReviewState::Rejected)
        ->and($field->reviewed_by)->toBe($this->member->getKey())
        ->and($field->reviewed_at)->not->toBeNull()
        ->and($field->final_value)->toBeNull()
        ->and($field->trashed())->toBeFalse()
        ->and(KeyDate::query()->count())->toBe(0);

    expect(DB::table('audit_log')->where('action', 'extraction.field_rejected')->count())->toBe(1);
});

it('refuses a second confirmation in a sentence rather than a 500', function (): void {
    /*
     * *"Not a race and not an error worth a stack trace: two tabs, or a double
     * press on a slow connection."* The refusal exists because confirming twice
     * would create the key date twice and `key_dates` has no natural key to stop
     * it — so the assertion that matters is the **count**, not the status.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Closing Date', '2026-08-28'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    $this->post(confirmUrl($this->deal, $this->extraction, $field))
        ->assertRedirect()
        ->assertSessionHasErrors('value');

    expect(session('errors')->first('value'))->toContain('already decided')
        ->and(KeyDate::query()->count())->toBe(1);
});

it('refuses to put something that is not a date on the calendar', function (): void {
    /*
     * F10.2's whole point, at the last moment it can be enforced. `ReadProposals`
     * hands a value like *"ten days after closing"* through verbatim on purpose,
     * because S66's job is showing a person what the model actually said — and
     * this is what happens if they press Confirm on it anyway.
     *
     * `Carbon::parse` would read it as **now**, and today's date on a contingency
     * calendar with somebody's name against it is precisely the failure the
     * review screen exists to prevent. So the second assertion is not that no row
     * was written but that no row was written *with today's date on it*, which is
     * the shape the defect would take.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Inspection objection', 'ten days after closing'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))
        ->assertRedirect()
        ->assertSessionHasErrors('value');

    expect(session('errors')->first('value'))->toContain('not a date')
        ->and(KeyDate::query()->count())->toBe(0)
        ->and(KeyDate::query()->where('date', '2026-07-01')->count())->toBe(0)
        // Nothing about the refused value in the message: it came out of
        // somebody's contract and this string reaches a flash and a log.
        ->and(session('errors')->first('value'))->not->toContain('ten days after closing');

    // And the proposal is still there to be edited or discarded.
    expect($field->refresh()->isPending())->toBeTrue();
});

it('moves a deadline the deal already has instead of adding a second one', function (): void {
    /*
     * #116's *"conflict with existing"*, resolved in the writer rather than on
     * the screen. Without it, confirming would add an *Inspection Objection
     * Deadline* beside the *Inspection objection* somebody typed last week — two
     * live dates, both feeding reminders, and nothing on any screen saying they
     * are about the same thing. `KeyDateNames` drops the noise words a form adds,
     * which is what makes the two one key.
     *
     * And moving is what runs the cascade, which is the half that would be easy
     * to lose: everything derived from that deadline follows it. The derived row
     * below is what proves the confirmation went through `SaveKeyDate::edit()`
     * rather than writing the column directly.
     */
    $existing = KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-07-20',
    ]);

    $derived = KeyDate::factory()->derivedFrom($existing, 5, OffsetBasis::Calendar)->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection resolution',
    ]);

    expect($derived->date->toDateString())->toBe('2026-07-25');

    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Inspection Objection Deadline', '2026-07-28'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    expect(KeyDate::query()->where('deal_id', $this->deal->getKey())->count())->toBe(2)
        ->and($existing->refresh()->date->toDateString())->toBe('2026-07-28')
        ->and($derived->refresh()->date->toDateString())->toBe('2026-08-02')
        /*
         * The row's own `source` is deliberately left alone: a date a person
         * typed and then corrected against a contract is still a date a person
         * owns, and rewriting the provenance would lose that it was ever typed.
         */
        ->and($existing->source)->toBe(KeyDateSource::Manual);

    // …and the proposal points at the row it moved, so S66 can link to it.
    expect($field->refresh()->created_record_id)->toBe($existing->getKey());
});

it('will not accept a contract’s dates in a batch, and will accept an inspection’s tasks', function (): void {
    /*
     * Screen Inventory over S66: it *"must make an unreviewed date impossible to
     * accept by accident, and **never default to 'confirm all'**."* #117 reasons
     * that the same control is defensible for tasks, because *"an unwanted task
     * is an annoyance, not a legal exposure"*.
     *
     * The refusal is on the server, keyed on `extractions.kind`, so a request
     * crafted by hand cannot bulk-confirm a contract's dates any more than the
     * screen can offer to. A rule stated only in a component is a rule the next
     * caller lacks.
     */
    $dates = collect(['2026-07-25', '2026-08-28'])->map(fn (string $day): ExtractedField => proposalOn(
        $this->extraction,
        fn ($factory) => $factory->keyDate('Deadline '.$day, $day),
    ));

    $this->post("/deals/{$this->deal->getKey()}/extractions/{$this->extraction->getKey()}/fields", [
        'ids' => $dates->map(fn (ExtractedField $field): string => $field->getKey())->all(),
    ])->assertForbidden();

    expect(KeyDate::query()->count())->toBe(0)
        ->and(ExtractedField::query()->where('review_state', 'pending')->count())->toBe(2);

    $inspection = Extraction::factory()->inspection()->complete()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $this->extraction->document_id,
    ]);

    $findings = collect(['Repair the handrail', 'Reseat the downspout'])
        ->map(fn (string $title): ExtractedField => proposalOn(
            $inspection,
            fn ($factory) => $factory->task($title),
        ));

    $this->post("/deals/{$this->deal->getKey()}/extractions/{$inspection->getKey()}/fields", [
        'ids' => $findings->map(fn (ExtractedField $field): string => $field->getKey())->all(),
    ])->assertRedirect();

    expect(Task::query()->where('deal_id', $this->deal->getKey())->count())->toBe(2);
});

it('lets somebody read the proposals without letting them accept one', function (): void {
    /*
     * The reason `extraction.confirm` is its own key rather than folded into
     * `deals.manage`. Viewing is `deals.view`, deliberately wider than either:
     * *"the whole argument for the review screen is that a human looks at it, and
     * a role that can see the deal but not the proposals cannot be asked to check
     * anybody's work."*
     *
     * Both halves on one actor, because a 403 proved without the matching read is
     * a 403 that could equally mean the person cannot see the deal at all.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->keyDate('Closing Date', '2026-08-28'));

    app(TeamContext::class)->runFor($this->team, function (): void {
        $membership = TeamMembership::query()->where('person_id', $this->member->getKey())->sole();

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'reader_only_extraction_review',
            'name' => 'Reader Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [Permissions::VIEW_DEALS])->pluck('id')->all(),
        );

        $membership->roles()->sync([$role->getKey()]);
    });

    $this->actingAsPerson($this->member, $this->team);

    $this->get("/deals/{$this->deal->getKey()}/extractions/{$this->extraction->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canConfirm', false));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertForbidden();
    $this->delete(confirmUrl($this->deal, $this->extraction, $field))->assertForbidden();

    expect(KeyDate::query()->count())->toBe(0)
        ->and($field->refresh()->isPending())->toBeTrue();
});

it('files an accepted finding under Inspection, due when the objection is', function (): void {
    /*
     * #117 step 4. **Computed once, at acceptance, and an ordinary typed date
     * afterwards** — the backlog audit found that the issue promised a live
     * cascade the schema cannot do, because `tasks` has a plain `due_date` and no
     * anchor, offset or basis. Rather than promise it anyway the deadline is
     * resolved at the moment somebody accepts the task.
     *
     * The stage is matched on its **name**, because there is no stage type and a
     * team may call theirs anything. The second stage is the control: a matcher
     * that took the first stage on the workflow would pass with one.
     */
    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Under Contract',
        'sort_order' => 0,
    ]);

    $inspectionStage = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Inspection',
        'sort_order' => 1,
    ]);

    KeyDate::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'name' => 'Inspection objection',
        'date' => '2026-07-25',
    ]);

    $inspection = Extraction::factory()->inspection()->complete()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'document_id' => $this->extraction->document_id,
    ]);

    $finding = proposalOn($inspection, fn ($factory) => $factory->task('Repair the loose stair handrail'));

    $this->post(confirmUrl($this->deal, $inspection, $finding))->assertRedirect();

    $task = Task::query()->sole();

    expect($task->title)->toBe('Repair the loose stair handrail')
        ->and($task->stage_id)->toBe($inspectionStage->getKey())
        ->and($task->due_date->toDateString())->toBe('2026-07-25')
        ->and($task->source)->toBe(TaskSource::Extracted)
        /*
         * **Never required.** An accepted finding is work somebody chose to take
         * on, and marking it required would let it block a
         * `required_tasks_complete` gate on the very stage it was filed under —
         * the same trap `AdvanceWorkflow::override()`'s follow-up task avoids.
         */
        ->and($task->is_required)->toBeFalse()
        ->and($task->description)->toContain('lower bracket');
});

it('puts a provision on the timeline rather than in the calendar', function (): void {
    /*
     * PRD F10.1: *"capture additional provisions as notes."* Not a `key_dates`
     * row (it is not a date), not a task (nothing has to be done about "seller
     * conveys the washer"), and not a new table.
     *
     * **Internal, always.** A provision is a sentence a model wrote about
     * somebody's contract, and putting it in front of a client on the strength of
     * one internal confirmation is not a decision this path gets to make.
     */
    $field = proposalOn($this->extraction, fn ($factory) => $factory
        ->provision('Seller conveys the two garage door openers at Possession.'));

    $this->post(confirmUrl($this->deal, $this->extraction, $field))->assertRedirect();

    $event = DB::table('activity_events')
        ->where('deal_id', $this->deal->getKey())
        ->where('event_type', 'note.added')
        ->sole();

    expect($event->summary)->toBe('Seller conveys the two garage door openers at Possession.')
        ->and((bool) $event->is_client_visible)->toBeFalse();

    expect(KeyDate::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0)
        ->and($field->refresh()->review_state)->toBe(ExtractedFieldReviewState::Confirmed)
        // An activity event is not a record the screen can link back to, so the
        // pointer stays empty rather than naming a row nobody can open.
        ->and($field->created_record_id)->toBeNull();
});
