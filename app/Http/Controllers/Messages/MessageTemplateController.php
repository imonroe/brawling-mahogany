<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messages;

use App\Enums\MessageChannel;
use App\Enums\ParticipantRole;
use App\Enums\RecipientRuleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\MessageTemplateRules;
use App\Http\Requests\Messages\StoreMessageTemplateRequest;
use App\Http\Requests\Messages\UpdateMessageTemplateRequest;
use App\Mail\MessageTemplateTestMail;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Messages\MergeContext;
use App\Support\Messages\MergeField;
use App\Support\Messages\MergeFields;
use App\Support\Messages\RecipientRule;
use App\Support\Messages\RenderedMessage;
use App\Support\Messages\RenderMessage;
use App\Support\Messages\ResolveRecipients;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S45 and S46 — the message templates list and the editor (PRD §4.5 F5.5,
 * F5.6 · issue #90).
 *
 * ## The route says *messages*, and the Screen Inventory said *emails*
 *
 * Deliberate, and it is the correction the issue exists to implement. PRD
 * §7.12: *"`Email Template` points the wrong way, and should generalise"* —
 * the channel enum carries `push` and the deferred `sms`, so a route reading
 * `/templates/emails` would be a lie in the URL the first time somebody wrote
 * a push template. The epic's own child list already calls these *message*
 * templates. `docs/Screen Inventory.md` moved with the code.
 *
 * ## A template is archived, never deleted
 *
 * There is **no destroy route** — not one that refuses, which is a route
 * somebody reaches by guessing a verb. Frontend conventions §4's rule, and it
 * applies here for the reason it applies to deal types: an automation *points
 * at* a template, and deleting the one three automations stand on breaks
 * three automations to solve a tidiness problem. The in-use count is shown
 * **before** the choice.
 *
 * ## Preview renders the draft, not the saved row
 *
 * Issue #90: *"Live preview renders against real merge data from a chosen
 * deal, not lorem ipsum. The whole point is seeing what the client will
 * actually receive."* So `preview()` takes the unsaved body from the form and
 * renders it against a real deal — and it deliberately does **not** validate
 * first, because a broken merge field is exactly what somebody opens the
 * preview to find.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $templates = MessageTemplate::query()
            // One query for the page, not one per row — the shape a screen
            // whose whole job is a count per row is most likely to grow an
            // N+1 in (`DealTypesBudgetTest`'s finding, one screen over).
            //
            // **Live** automations: a soft-deleted workflow template takes its
            // stages' automations off every screen without deleting them, and
            // a count somebody cannot chase down is noise in the one place the
            // product asked them to think.
            ->withCount('liveActionDefinitions')
            ->orderBy('channel')
            ->orderBy('name')
            ->get();

        return Inertia::render('Templates/Messages/Index', [
            'templates' => $templates->map(self::row(...))->values()->all(),
            'channels' => MessageChannel::selectableOptions(),
            'recipientRules' => self::recipientRulesByChannel(),
            'participantRoles' => ParticipantRole::options(),
            'can' => [
                'manage' => $request->user()?->can('create', MessageTemplate::class) ?? false,
            ],
        ]);
    }

    public function show(Request $request, MessageTemplate $messageTemplate): Response
    {
        $this->authorize('view', $messageTemplate);

        return $this->editor($request, $messageTemplate, null);
    }

    public function store(StoreMessageTemplateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $template = new MessageTemplate;

        $template->fill($request->validated())->save();

        $audit->record(
            action: 'message_template.created',
            auditable: $template,
            teamId: $template->team_id,
            actorPersonId: $request->user()?->getKey(),
            after: ['name' => $template->name, 'channel' => $template->channel->value],
        );

        return to_route('message-templates.show', $template);
    }

    public function update(UpdateMessageTemplateRequest $request, MessageTemplate $messageTemplate, AuditLogger $audit): RedirectResponse
    {
        $messageTemplate->fill($request->validated())->save();

        $audit->record(
            action: 'message_template.updated',
            auditable: $messageTemplate,
            teamId: $messageTemplate->team_id,
            actorPersonId: $request->user()?->getKey(),
            after: ['name' => $messageTemplate->name, 'channel' => $messageTemplate->channel->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back(fallback: route('message-templates.show', $messageTemplate));
    }

    /**
     * Out of every picker, and the automations already on it keep it.
     */
    public function archive(Request $request, MessageTemplate $messageTemplate, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('archive', $messageTemplate);

        $messageTemplate->forceFill(['archived_at' => now()])->save();

        $audit->record(
            action: 'message_template.archived',
            auditable: $messageTemplate,
            teamId: $messageTemplate->team_id,
            actorPersonId: $request->user()?->getKey(),
            after: ['name' => $messageTemplate->name],
        );

        return to_route('message-templates.index');
    }

    public function restore(Request $request, MessageTemplate $messageTemplate, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('restore', $messageTemplate);

        /*
         * Clearing `archived_at` moves the row back **into** the partial
         * unique index, so a restore can collide exactly the way a create can
         * — archiving frees the name, which is the point. Asked through the
         * same function the create rule uses; two implementations of one index
         * means the one nobody tested is the one that 500s.
         */
        if (MessageTemplateRules::nameIsTaken($messageTemplate->name, $messageTemplate->channel, $messageTemplate)) {
            return back()->withErrors([
                'restore' => __('Another template on this channel has taken that name. Rename that one first.'),
            ]);
        }

        $messageTemplate->forceFill(['archived_at' => null])->save();

        $audit->record(
            action: 'message_template.restored',
            auditable: $messageTemplate,
            teamId: $messageTemplate->team_id,
            actorPersonId: $request->user()?->getKey(),
            after: ['name' => $messageTemplate->name],
        );

        return to_route('message-templates.index');
    }

    /**
     * S46's live preview — the **draft**, against a real deal.
     */
    public function preview(Request $request, MessageTemplate $messageTemplate): Response
    {
        $this->authorize('view', $messageTemplate);

        /*
         * The draft's own shape is checked and its *contents* are not. A
         * missing merge field is the thing somebody opened the preview to
         * find, so `ValidMergeFields` is deliberately absent here — it runs on
         * save, where it belongs.
         */
        $draft = $request->validate([
            'deal' => ['required', 'string'],
            /*
             * The channel is part of the draft, and leaving it out was the
             * last of three narrowings still taken from the saved row: a push
             * template switched to Email in the form previewed with a null
             * subject and no HTML body, because `MessageChannel::hasSubject()`
             * was asked of the channel in the database rather than the one on
             * screen. The reader's own words came back missing.
             */
            'channel' => ['nullable', Rule::in(array_keys(MessageChannel::selectableOptions()))],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['nullable', 'string', 'max:100000'],
            'body_text' => ['nullable', 'string', 'max:100000'],
            // The recipient rule too, so *"would reach"* answers for the draft
            // rather than for the last save.
            'recipient_rule' => ['nullable', 'array'],
            'recipient_rule.type' => ['nullable', Rule::enum(RecipientRuleType::class)],
            'recipient_rule.participantRole' => ['nullable', Rule::enum(ParticipantRole::class)],
        ]);

        $channel = isset($draft['channel'])
            ? MessageChannel::from((string) $draft['channel'])
            : $messageTemplate->channel;

        $rehearsal = $messageTemplate->replicate();
        $rehearsal->forceFill([
            'channel' => $channel,
            'subject' => $channel->hasSubject() ? ($draft['subject'] ?? null) : null,
            'body_html' => $channel->hasHtmlBody() ? ($draft['body_html'] ?? null) : null,
            'body_text' => $draft['body_text'] ?? '',
            'recipient_rule' => $this->draftRecipientRule($draft, $messageTemplate),
        ]);

        return $this->editor($request, $messageTemplate, $rehearsal, (string) $draft['deal']);
    }

    /**
     * S46's test send: *"reaches the author only"* (issue #90).
     *
     * Not the recipient rule, and not an address anybody types. The one
     * address this can reach is the membership of the person who pressed the
     * button — which makes it impossible for a test to become a real message
     * to a real client, whatever is stored in the template.
     */
    public function test(Request $request, MessageTemplate $messageTemplate, RenderMessage $renderer, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('test', $messageTemplate);

        abort_unless($messageTemplate->channel === MessageChannel::Email, 422);

        $validated = $request->validate(['deal' => ['required', 'string']]);

        $team = $this->team();
        $deal = $this->deal((string) $validated['deal']);

        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        $membership = $person->membershipIn($team);
        $address = $membership?->email;

        if ($address === null || $address === '') {
            return back()->withErrors([
                'test' => __('Add an email address to your own directory entry first — a test can only go to you.'),
            ]);
        }

        $rendered = $renderer->render($messageTemplate, MergeContext::forCurrentStageOf($deal, $team));

        Mail::to($address)->send(new MessageTemplateTestMail($messageTemplate, $rendered, $team));

        $audit->record(
            action: 'message_template.tested',
            auditable: $messageTemplate,
            teamId: $messageTemplate->team_id,
            actorPersonId: $person->getKey(),
            after: ['name' => $messageTemplate->name],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Sent to you, and only to you.'),
        ]);

        return back(fallback: route('message-templates.show', $messageTemplate));
    }

    /**
     * The editor payload, shared by `show()` and `preview()`.
     *
     * One builder, because the two differ in exactly one thing — whether the
     * preview was rendered from the saved row or from what is in the form —
     * and a second copy would drift on everything else.
     */
    private function editor(Request $request, MessageTemplate $template, ?MessageTemplate $rehearsal, ?string $dealId = null): Response
    {
        $team = $this->team();

        /*
         * Named columns and no relations. The list renders `displayName()`,
         * which reads two of this table's own columns — and the deal that is
         * actually previewed loads what it needs itself, through
         * `MergeContext::forCurrentStageOf()` and `MergeFields::resolve()`.
         *
         * Eager-loading participants and properties here hydrated a hundred
         * models so that one of them could be rendered. `CLAUDE.md`: *"Before
         * adding a `with()`, name the cell that reads it; if there isn't one,
         * that is the finding."* It is a fixed per-page cost, so a query-count
         * budget would not have found it either.
         */
        $deals = Deal::query()
            ->select(['id', 'team_id', 'name', 'generated_name', 'updated_at'])
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();

        $deal = $dealId !== null
            ? $deals->firstWhere('id', $dealId) ?? $this->deal($dealId)
            : $deals->first();

        $rendered = $deal instanceof Deal
            ? app(RenderMessage::class)->render(
                $rehearsal ?? $template,
                MergeContext::forCurrentStageOf($deal, $team),
            )
            : null;

        return Inertia::render('Templates/Messages/Show', [
            'template' => [
                ...self::row($template),
                'subject' => $template->subject,
                'bodyHtml' => $template->body_html,
                'bodyText' => $template->body_text,
                'fromIdentity' => $template->from_identity,
                'recipientRule' => $template->recipient_rule,
            ],
            'mergeFields' => array_map(
                static fn (MergeField $field): array => $field->toArray(),
                MergeFields::all(),
            ),
            'channels' => MessageChannel::selectableOptions(),
            'recipientRules' => self::recipientRulesByChannel(),
            'participantRoles' => ParticipantRole::options(),
            'deals' => $deals->map(fn (Deal $row): array => [
                'id' => $row->getKey(),
                'name' => $row->displayName(),
            ])->values()->all(),
            'preview' => $rendered instanceof RenderedMessage
                ? [
                    ...$rendered->toArray(),
                    'dealId' => $deal->getKey(),
                    'recipients' => $this->recipientNames($rehearsal ?? $template, $deal, $team),
                ]
                : null,
            'can' => [
                'update' => $request->user()?->can('update', $template) ?? false,
            ],
        ]);
    }

    /**
     * The rule the draft is addressed to.
     *
     * Falls back to the saved rule only when the draft names **no rule at
     * all** — an older page, or a caller that did not send one. A draft that
     * names a rule is used as it stands, *including* a half-finished one.
     *
     * The first version fell back whenever the draft was incomplete, and
     * "incomplete" turned out to be one click into editing: the role picker
     * carries a placeholder and does not auto-select, so choosing *a
     * participant in a named role* and pressing Refresh showed the **previous**
     * rule's recipients underneath a form that said something else. That is
     * the failure this screen's own test is written around — the preview
     * naming one person and the send reaching another — reached from the other
     * side. An unfinished rule resolves to nobody, and this screen already
     * treats an empty recipient list as the answer worth showing.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function draftRecipientRule(array $draft, MessageTemplate $template): array
    {
        $rule = $draft['recipient_rule'] ?? null;

        if (! is_array($rule) || ! is_string($rule['type'] ?? null)) {
            return $template->recipient_rule;
        }

        return array_filter([
            'type' => $rule['type'],
            'participantRole' => is_string($rule['participantRole'] ?? null)
                ? $rule['participantRole']
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Every channel's recipient rules, so the page can narrow as the channel
     * changes rather than as the row is saved.
     *
     * Sent whole, keyed by channel, the way `automationShapes` already is one
     * screen over. Sending the rules for the *saved* channel is the hazard
     * Frontend conventions §3 names — the props are the last response that
     * landed, and on this screen two of the three channel-dependent
     * narrowings (`hasSubject`, `hasHtml`) are reactive while the third was
     * not. An email template switched to push went on offering client
     * recipients that the validator then refused, and a push template
     * switched to email could not pick one until it had been saved.
     *
     * @return array<string, array<string, string>>
     */
    private static function recipientRulesByChannel(): array
    {
        $rules = [];

        foreach (MessageChannel::selectableOptions() as $value => $label) {
            $channel = MessageChannel::from($value);

            $rules[$value] = RecipientRuleType::optionsFor($channel);
        }

        return $rules;
    }

    /**
     * Who this would actually reach, on the deal being previewed.
     *
     * Names rather than addresses: the preview is answering *"is this going to
     * the right person"*, and an address list on screen is a client's contact
     * details rendered into a template editor for no reason.
     *
     * An **empty** list is the answer worth showing. A template addressed to
     * the Lender on a cash purchase reaches nobody, and finding that out in
     * the editor is the whole point of putting it here.
     *
     * @return list<string>
     */
    private function recipientNames(MessageTemplate $template, ?Deal $deal, Team $team): array
    {
        if (! $deal instanceof Deal) {
            return [];
        }

        /*
         * `tryFromArray`, because this may be a **draft** rule somebody is
         * still typing. "A participant in a named role" with no role yet is
         * one click into editing, and it reaches nobody — which is a true
         * answer, and one this panel already knows how to render.
         */
        $rule = RecipientRule::tryFromArray($template->recipient_rule);

        if ($rule === null) {
            return [];
        }

        return array_values(
            app(ResolveRecipients::class)
                ->for($rule, $deal, $team)
                ->map(fn (TeamMembership $membership): string => $membership->fullName())
                ->all(),
        );
    }

    private function team(): Team
    {
        $team = app(TeamContext::class)->get();

        abort_unless($team instanceof Team, 404);

        return $team;
    }

    /**
     * A deal of this team's, or a 404.
     *
     * The global scope already refuses another team's, so `findOrFail` is the
     * whole check — but it is spelled out here because a preview takes a deal
     * id straight from a request body, which is the vector
     * `CrossTenantAccessTest` names as *"a foreign id in a form"*.
     */
    private function deal(string $id): Deal
    {
        /** @var Deal */
        return Deal::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(MessageTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'name' => $template->name,
            'channel' => $template->channel->value,
            'channelLabel' => $template->channel->label(),
            'recipient' => $template->recipientRule()->describe(),
            'archivedAt' => $template->archived_at?->toIso8601String(),
            /*
             * S45's *"in use by N automations"*. Shown before the choice
             * rather than reported after it — the rule every lookup screen in
             * this product follows.
             */
            'inUse' => (int) ($template->getAttribute('live_action_definitions_count')
                ?? $template->inUseCount()),
            'url' => '/templates/messages/'.$template->getKey(),
        ];
    }
}
