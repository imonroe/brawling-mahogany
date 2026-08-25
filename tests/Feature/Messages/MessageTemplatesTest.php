<?php

declare(strict_types=1);

use App\Enums\MessageChannel;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Mail\MessageTemplateTestMail;
use App\Models\ActionDefinition;
use App\Models\AuditEntry;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\TeamMembership;
use App\Models\WorkflowTemplate;
use App\Support\Deals\DealRoster;
use App\Support\Messages\ChannelMismatch;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Mail;

/**
 * S45, S46 — message templates and the editor (PRD F5.5, F5.6 · issue #90).
 *
 * The definition of done is three sentences, and each has cases here:
 *
 *  - *"Templates save with validated merge fields; an invalid field blocks the
 *    save with a useful message."*
 *  - *"Preview renders against a real deal."*
 *  - *"Test send reaches the author only."*
 *
 * Plus the rule S45 exists for: an in-use count shown **before** the choice,
 * and archiving rather than deleting.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    // `templates.manage` belongs to Team Owner, not Team Member.
    $owner = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->sole());

    $this->owner = $owner;
    $this->actingAsPerson($this->enrollTwoFactor($owner->person), $this->team);
});

function messageTemplate(array $attributes = []): MessageTemplate
{
    return app(TeamContext::class)->runFor(
        test()->team,
        fn (): MessageTemplate => MessageTemplate::factory()->create([
            'team_id' => test()->team->getKey(),
            ...$attributes,
        ]),
    );
}

/** A deal with a main contact, so the preview has real data to render. */
function previewDeal(): Deal
{
    $team = test()->team;

    return app(TeamContext::class)->runFor($team, function () use ($team): Deal {
        $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

        app(DealRoster::class)->add(
            $deal,
            TeamMembership::factory()->create([
                'team_id' => $team->getKey(),
                'first_name' => 'Emily',
                'last_name' => 'Bosart',
            ]),
            ParticipantRole::Seller,
            isPrimary: true,
        );

        return $deal->fresh();
    });
}

it('lists a team’s templates with the count of automations standing on them', function (): void {
    $template = messageTemplate(['name' => 'Inspection scheduled']);

    $workflowTemplate = WorkflowTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $stage = StageTemplate::factory()->create(['workflow_template_id' => $workflowTemplate->getKey()]);

    ActionDefinition::factory()->sendingEmail()->count(2)->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'message_template_id' => $template->getKey(),
    ]);

    $this->get('/templates/messages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Templates/Messages/Index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Inspection scheduled')
            // The number before the choice, which is the whole reason S45 has
            // an "in use by N automations" state.
            ->where('templates.0.inUse', 2)
            ->where('templates.0.recipient', 'the deal’s main contact'));
});

it('creates a template and audits it', function (): void {
    $this->post('/templates/messages', [
        'name' => 'Inspection scheduled',
        'channel' => MessageChannel::Email->value,
        'subject' => 'Your inspection is booked',
        'body_text' => 'Hello {{ client_first_name }}.',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertRedirect();

    $template = MessageTemplate::query()->sole();

    expect($template->team_id)->toBe($this->team->getKey())
        ->and($template->channel)->toBe(MessageChannel::Email)
        ->and(AuditEntry::query()->where('action', 'message_template.created')->exists())->toBeTrue();
});

it('never lets a request body choose the team', function (): void {
    [$other] = $this->teamWithMember();

    $this->post('/templates/messages', [
        'name' => 'Inspection scheduled',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
        'team_id' => $other->getKey(),
    ])->assertRedirect();

    // `team_id` is absent from #[Fillable] and `BelongsToTeam` fills it from
    // the resolved team. Same rule as every other business table.
    expect(MessageTemplate::query()->sole()->team_id)->toBe($this->team->getKey());
});

/**
 * F5.6's *"validated at save time"*, which is the whole point of the field
 * registry existing.
 */
it('refuses a merge field nothing answers to, on the field it was written in', function (): void {
    $this->post('/templates/messages', [
        'name' => 'Broken',
        'channel' => MessageChannel::Email->value,
        'subject' => 'About {{ clietn_name }}',
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('subject');

    expect(MessageTemplate::query()->count())->toBe(0);
});

it('refuses a malformed token, which is the one a strict scan would miss', function (): void {
    // `{{ client name }}` is not a token at all, so a validator that scanned
    // for well-formed tokens and checked those against the registry would see
    // nothing wrong and let the braces through into somebody's inbox.
    $this->post('/templates/messages', [
        'name' => 'Broken',
        'channel' => MessageChannel::Email->value,
        'subject' => 'ok',
        'body_text' => 'Hello {{ client name }}',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('body_text');
});

/**
 * The one a strict scan cannot see, because it is not a token at all.
 *
 * A dropped brace is the likeliest typo in something hand-typed, and it used
 * to save with no errors, render literally into a client's inbox, and report
 * `isComplete() === true`.
 */
it('refuses a merge field with a brace missing', function (): void {
    $this->post('/templates/messages', [
        'name' => 'Broken',
        'channel' => MessageChannel::Email->value,
        'subject' => 'Hi {{ client_name }',
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('subject');

    expect(MessageTemplate::query()->count())->toBe(0);
});

it('saves an HTML body carrying a media query', function (): void {
    // Design System §12 wants a `<style>` block as a progressive enhancement,
    // and its nested rules close with `}}`. The first version of the stray
    // brace check refused it.
    $this->post('/templates/messages', [
        'name' => 'Branded',
        'channel' => MessageChannel::Email->value,
        'subject' => 'Your inspection',
        'body_html' => '<style>@media (max-width:600px){.card{width:100%}}</style><p>Hi {{ client_first_name }}</p>',
        'body_text' => 'Hi {{ client_first_name }}',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasNoErrors();

    expect(MessageTemplate::query()->sole()->body_html)->toContain('@media');
});

it('refuses a subject carrying a line break', function (): void {
    // A subject is a mail header. Symfony folds a newline into encoded words
    // rather than injecting one, so this is not an exploit — it is the rule
    // the merged values are already held to, applied to the other half of the
    // same string.
    $this->post('/templates/messages', [
        'name' => 'Injected',
        'channel' => MessageChannel::Email->value,
        'subject' => "Your inspection\r\nBcc: attacker@example.test",
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('subject');
});

it('refuses a field that exists and cannot resolve yet, naming its slice', function (): void {
    $this->post('/templates/messages', [
        'name' => 'Too early',
        'channel' => MessageChannel::Email->value,
        'subject' => 'ok',
        'body_text' => 'Track it at {{ status_page_link }}',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('body_text');

    expect(session('errors')->first('body_text'))->toContain('Slice 4');
});

it('prohibits a subject on a channel that has none', function (): void {
    // A push notification has a title and a body. A stored subject on it is a
    // promise the product does not keep.
    $this->post('/templates/messages', [
        'name' => 'Stage moved',
        'channel' => MessageChannel::Push->value,
        'subject' => 'Something',
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ])->assertSessionHasErrors('subject');
});

it('refuses a client recipient on the push channel', function (): void {
    // PRD F12.2: push is an internal channel and carries nothing
    // client-facing. A lock screen is a third party's server and somebody's
    // pocket.
    $this->post('/templates/messages', [
        'name' => 'Stage moved',
        'channel' => MessageChannel::Push->value,
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('recipient_rule.type');
});

it('refuses a channel nothing can send', function (): void {
    // `sms` is a value PRD §7.12 names and nothing delivers. A template on it
    // could never leave the building.
    $this->post('/templates/messages', [
        'name' => 'Texted',
        'channel' => MessageChannel::Sms->value,
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('channel');
});

it('wants the role when the rule is a participant role', function (): void {
    $this->post('/templates/messages', [
        'name' => 'To the seller',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::ParticipantRole->value],
    ])->assertSessionHasErrors('recipient_rule.participantRole');

    $this->post('/templates/messages', [
        'name' => 'To the seller',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => [
            'type' => RecipientRuleType::ParticipantRole->value,
            'participantRole' => ParticipantRole::Seller->value,
        ],
    ])->assertSessionHasNoErrors();

    expect(MessageTemplate::query()->sole()->recipientRule()->participantRole)
        ->toBe(ParticipantRole::Seller);
});

it('refuses a duplicate name on the same channel and allows it on another', function (): void {
    messageTemplate(['name' => 'Inspection scheduled']);

    $this->post('/templates/messages', [
        'name' => 'inspection scheduled',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasErrors('name');

    // Same words, different channel: the email to the seller and the push to
    // the agent are different rows and reasonably share a name.
    $this->post('/templates/messages', [
        'name' => 'Inspection scheduled',
        'channel' => MessageChannel::Push->value,
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ])->assertSessionHasNoErrors();

    expect(MessageTemplate::query()->count())->toBe(2);
});

it('folds case the way the index folds it', function (string $first, string $second): void {
    /*
     * PHP and Postgres genuinely disagree — `mb_strtolower('ΑΣ')` is `ας`
     * (final sigma) and Postgres `lower()` gives `ασ` — so a rule that folded
     * its bind in PHP would let a duplicate through to the index as a 500.
     * The ASCII row is a control: it always worked.
     */
    $payload = fn (string $name): array => [
        'name' => $name,
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ];

    $this->post('/templates/messages', $payload($first))->assertSessionHasNoErrors();
    $this->post('/templates/messages', $payload($second))->assertSessionHasErrors('name');

    expect(MessageTemplate::query()->count())->toBe(1);
})->with([
    'final sigma' => ['ΑΣ update', 'ΑΣ update'],
    'plain I stored, dotted I typed' => ['Istanbul update', 'İstanbul update'],
    'plain ascii (control — always worked)' => ['Listing live', 'LISTING LIVE'],
]);

it('has no route that deletes a template', function (): void {
    // Not "the destroy action refuses" — there is no destroy action. An
    // automation points at a template, so deleting one breaks three
    // automations to solve a tidiness problem.
    $template = messageTemplate();

    $this->delete("/templates/messages/{$template->getKey()}")->assertStatus(405);

    expect(MessageTemplate::query()->whereKey($template->getKey())->exists())->toBeTrue();
});

it('archives a template, and the automations on it keep it', function (): void {
    $template = messageTemplate();

    $workflowTemplate = WorkflowTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $stage = StageTemplate::factory()->create(['workflow_template_id' => $workflowTemplate->getKey()]);

    $automation = ActionDefinition::factory()->sendingEmail()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'message_template_id' => $template->getKey(),
    ]);

    $this->post("/templates/messages/{$template->getKey()}/archive")
        ->assertRedirect('/templates/messages');

    expect($template->fresh()->isArchived())->toBeTrue()
        // The whole argument for archiving over deleting.
        ->and($automation->fresh()->message_template_id)->toBe($template->getKey());
});

it('frees the name when a template is archived, and refuses a restore that would collide', function (): void {
    $template = messageTemplate(['name' => 'Inspection scheduled']);

    $this->post("/templates/messages/{$template->getKey()}/archive")->assertRedirect();

    // Archiving frees the name — that is the documented way out of "I archived
    // the wrong one, let me start clean".
    $this->post('/templates/messages', [
        'name' => 'Inspection scheduled',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertSessionHasNoErrors();

    // …which makes restoring able to collide, because clearing `archived_at`
    // moves the row back *into* the partial index.
    $this->post("/templates/messages/{$template->getKey()}/restore")
        ->assertSessionHasErrors('restore');

    expect($template->fresh()->isArchived())->toBeTrue();
});

it('refuses to edit an archived template', function (): void {
    $template = messageTemplate();

    $this->post("/templates/messages/{$template->getKey()}/archive");

    $this->patch("/templates/messages/{$template->getKey()}", [
        'name' => 'Renamed while archived',
        'channel' => MessageChannel::Email->value,
        'subject' => 'x',
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])->assertForbidden();
});

/**
 * The preview, which issue #90 is emphatic about: *"real merge data from a
 * chosen deal, not lorem ipsum."*
 */
it('previews the draft against a real deal rather than the saved row', function (): void {
    $template = messageTemplate([
        'subject' => 'Saved subject',
        'body_html' => null,
        'body_text' => 'Saved body',
    ]);

    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/preview", [
        'deal' => $deal->getKey(),
        'subject' => 'About {{ client_name }}',
        'body_text' => 'Hello {{ client_first_name }}.',
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Templates/Messages/Show')
            // The draft, not the saved row — which is what "live" means.
            ->where('preview.subject', 'About Emily Bosart')
            ->where('preview.bodyText', 'Hello Emily.')
            ->where('preview.isComplete', true)
            // Who it would actually reach, on this deal.
            ->where('preview.recipients', ['Emily Bosart']));
});

it('previews the draft’s channel, not the one last saved', function (): void {
    /*
     * The last of three channel-dependent narrowings still taken from the
     * saved row. A push template switched to Email in the form previewed with
     * a null subject and no HTML body — the reader's own words, missing.
     */
    $template = messageTemplate([
        'channel' => MessageChannel::Push,
        'subject' => null,
        'body_html' => null,
        'body_text' => 'x',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ]);

    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/preview", [
        'deal' => $deal->getKey(),
        'channel' => MessageChannel::Email->value,
        'subject' => 'About {{ client_name }}',
        'body_html' => '<p>Hello</p>',
        'body_text' => 'Hello.',
        'recipient_rule' => ['type' => RecipientRuleType::PrimaryContact->value],
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preview.subject', 'About Emily Bosart')
            ->where('preview.bodyHtml', '<p>Hello</p>')
            // …and *"would reach"* answers for the draft's rule too, or the
            // preview names one person and would send to another.
            ->where('preview.recipients', ['Emily Bosart']));
});

it('shows a merge field with nothing behind it rather than sending over it', function (): void {
    $template = messageTemplate();
    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/preview", [
        'deal' => $deal->getKey(),
        'subject' => 'x',
        'body_text' => 'See the listing at {{ mls_link }}',
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preview.unresolved', ['mls_link'])
            ->where('preview.isComplete', false));
});

it('refuses to preview against another team’s deal', function (): void {
    // A deal id straight from a request body is the vector
    // `CrossTenantAccessTest` names as "a foreign id in a form".
    $template = messageTemplate();

    [$other, $otherMember] = $this->teamWithMember();
    $foreign = app(TeamContext::class)->runFor(
        $other,
        fn (): Deal => Deal::factory()->create(['team_id' => $other->getKey()]),
    );

    $this->post("/templates/messages/{$template->getKey()}/preview", [
        'deal' => $foreign->getKey(),
        'body_text' => 'x',
    ])->assertNotFound();
});

it('sends a test to the author and to nobody else', function (): void {
    Mail::fake();

    $template = messageTemplate([
        'subject' => 'About {{ client_name }}',
        'body_text' => 'Hello.',
    ]);

    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/test", ['deal' => $deal->getKey()])
        ->assertRedirect();

    Mail::assertSent(MessageTemplateTestMail::class, function ($mail): bool {
        /*
         * The one address it can reach is the actor's own. Not the template's
         * recipient rule, and not an address anybody types — which is what
         * makes it impossible for a test to become a real message to a real
         * client.
         */
        return $mail->hasTo($this->owner->email);
    });

    Mail::assertSentCount(1);
});

it('refuses to test a template on a channel with no mail transport', function (): void {
    Mail::fake();

    $template = messageTemplate([
        'channel' => MessageChannel::Push,
        'subject' => null,
        'body_html' => null,
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ]);

    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/test", ['deal' => $deal->getKey()])
        ->assertStatus(422);

    Mail::assertNothingSent();
});

/**
 * The template's own PATCH was the **third** caller of the channel pairing
 * rule, and it was written without it.
 *
 * `SaveAutomationRequest` and `ActionDefinition::booted()` both refuse a
 * mismatch from the automation's end; `ActionDefinition::saving` never fires
 * when no automation row is being written. A `send_email` automation was left
 * pointing at a push template through the front door, with a 302.
 */
it('refuses a channel change that would strand the automations on it', function (): void {
    $template = messageTemplate(['name' => 'Property listed']);

    $workflowTemplate = WorkflowTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $stage = StageTemplate::factory()->create(['workflow_template_id' => $workflowTemplate->getKey()]);

    ActionDefinition::factory()->sendingEmail()->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'message_template_id' => $template->getKey(),
    ]);

    $this->patch("/templates/messages/{$template->getKey()}", [
        'name' => 'Property listed',
        'channel' => MessageChannel::Push->value,
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ])->assertSessionHasErrors('channel');

    expect($template->fresh()->channel)->toBe(MessageChannel::Email);
});

it('lets the channel change once nothing is standing on it', function (): void {
    // The control. A rule that refused every channel change would be a
    // different bug, and "no errors" alone passes on a refusal too.
    $template = messageTemplate();

    $this->patch("/templates/messages/{$template->getKey()}", [
        'name' => $template->name,
        'channel' => MessageChannel::Push->value,
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ])->assertSessionHasNoErrors();

    expect($template->fresh()->channel)->toBe(MessageChannel::Push)
        // …and the columns the new channel does not carry are cleared rather
        // than left behind. `prohibited` stops the write; nothing cleared them.
        ->and($template->fresh()->subject)->toBeNull()
        ->and($template->fresh()->body_html)->toBeNull();
});

it('holds the channel pairing on the model, for callers no request reaches', function (): void {
    $template = messageTemplate();

    $workflowTemplate = WorkflowTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $stage = StageTemplate::factory()->create(['workflow_template_id' => $workflowTemplate->getKey()]);

    app(TeamContext::class)->runFor($this->team, function () use ($stage, $template): void {
        ActionDefinition::factory()->sendingEmail()->create([
            'team_id' => $this->team->getKey(),
            'stage_template_id' => $stage->getKey(),
            'message_template_id' => $template->getKey(),
        ]);

        // No request in sight — which is the point. #92's instantiation and a
        // pack install are the callers this has to hold for.
        expect(fn () => $template->forceFill(['channel' => MessageChannel::Push])->save())
            ->toThrow(ChannelMismatch::class);
    });
});

/**
 * The number S45 exists to show has to be a number somebody can chase down.
 */
it('stops counting automations nobody can reach any more', function (): void {
    $template = messageTemplate();

    $workflowTemplate = WorkflowTemplate::factory()->create(['team_id' => $this->team->getKey()]);
    $stage = StageTemplate::factory()->create(['workflow_template_id' => $workflowTemplate->getKey()]);

    ActionDefinition::factory()->sendingEmail()->count(3)->create([
        'team_id' => $this->team->getKey(),
        'stage_template_id' => $stage->getKey(),
        'message_template_id' => $template->getKey(),
    ]);

    // The control: they count while the workflow template is live.
    expect($template->inUseCount())->toBe(3);

    $this->delete("/templates/{$workflowTemplate->getKey()}")->assertRedirect();

    // `WorkflowTemplateController::destroy` soft-deletes the template and
    // touches neither its stages nor their automations, so three automations
    // on no screen were still being reported to the reader as "keeping" it.
    expect($template->fresh()->inUseCount())->toBe(0);

    $this->get('/templates/messages')
        ->assertInertia(fn ($page) => $page->where('templates.0.inUse', 0));

    /*
     * …and the **guard** does not inherit that optimism.
     *
     * Narrowing the count is right — a number nobody can chase down is noise.
     * Narrowing the guard reopened the stranding one step back: PRD §9 gives a
     * soft delete a 30-day window, so those automations come back, and they
     * would come back pointing at a template that cannot send them.
     */
    $this->patch("/templates/messages/{$template->getKey()}", [
        'name' => $template->name,
        'channel' => MessageChannel::Push->value,
        'body_text' => 'ok',
        'recipient_rule' => ['type' => RecipientRuleType::TeamOwner->value],
    ])->assertSessionHasErrors('channel');

    expect($template->fresh()->channel)->toBe(MessageChannel::Email);
});

it('offers every channel’s recipient rules, so the picker can narrow with the form', function (): void {
    /*
     * Sending only the saved channel's rules made the picker offer
     * combinations the validator refused — and on S46, where `hasSubject` and
     * `hasHtml` are reactive, it made two of three narrowings live and the
     * third stale.
     */
    $this->get('/templates/messages')
        ->assertInertia(fn ($page) => $page
            ->has('recipientRules.email')
            ->has('recipientRules.push')
            ->has('recipientRules.email.primary_contact')
            // PRD F12.2: push is internal and carries nothing client-facing.
            ->missing('recipientRules.push.primary_contact')
            ->has('recipientRules.push.team_owners'));
});

it('refuses a test send from an archived template', function (): void {
    // Every other control on an archived template is disabled; the one that
    // puts mail on a transport was reading as a *view* and stayed live.
    Mail::fake();

    $template = messageTemplate();
    $deal = previewDeal();

    $this->post("/templates/messages/{$template->getKey()}/archive")->assertRedirect();

    $this->post("/templates/messages/{$template->getKey()}/test", ['deal' => $deal->getKey()])
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('refuses the whole screen to somebody without templates.manage', function (): void {
    // A Team Member runs deals and does not compose the words the product
    // sends on the team's behalf.
    $this->actingAsPerson($this->member, $this->team);

    $this->get('/templates/messages')->assertForbidden();
});
